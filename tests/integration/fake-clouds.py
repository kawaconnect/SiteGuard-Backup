#!/usr/bin/env python3
"""
Protocol fakes for the OAuth storage providers and Keystone, for storage.sh.

One TLS server (port 443, one certificate with every host name as a SAN) that
answers by Host header for:

  Dropbox     api.dropboxapi.com, content.dropboxapi.com
  Google      oauth2.googleapis.com, www.googleapis.com (Drive v3)
  Microsoft   login.microsoftonline.com, graph.microsoft.com (OneDrive),
              fake-my.sharepoint.com (upload sessions and download redirects)
  pCloud      api.pcloud.com, eapi.pcloud.com, p-fake1.pcloud.com (downloads)
  Keystone    keystone.test (v2.0 and v3, fronting a real Swift SAIO)
  Sign-in     accounts.google.com, www.dropbox.com, my.pcloud.com and
              login.microsoftonline.com authorize pages: they approve at once
              and redirect to the registered redirect URI (--redirect) with a
              code, enforcing each provider's redirect URI rules.

Each fake follows the provider's published API and is strict where the real
service is strict (Content-Type rules, chunk alignment, upload offsets, no
Authorization header on pre-authenticated URLs, region-bound tokens), so a
module that passes here speaks the documented protocol. It does not prove the
real service accepts it; see tests/README.md.

Only access tokens this process issued are accepted: every test starts from an
expired token, so the refresh path runs first. Requests are logged to stderr.

  fake-clouds.py <certfile> <keyfile> [--swift http://swift.test:8080] [--redirect URI]

Python standard library only.
"""
import base64
import hashlib
import json
import re
import secrets
import ssl
import sys
import threading
import time
import urllib.parse
import urllib.request
from email.parser import BytesParser
from email.policy import HTTP as HTTP_POLICY
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CERT, KEY = sys.argv[1], sys.argv[2]
SWIFT = 'http://swift.test:8080'
if '--swift' in sys.argv:
    SWIFT = sys.argv[sys.argv.index('--swift') + 1]
# The redirect URI "registered" with every fake app (exact match, like the real ones).
REGISTERED_REDIRECT = sys.argv[sys.argv.index('--redirect') + 1] if '--redirect' in sys.argv else None
CODES = {}             # authorization code -> {'provider', 'redirect_uri', 'client_id'}

LOCK = threading.Lock()
# Credentials the tests configure. Anything else is rejected.
CLIENTS = {
    'dropbox': ('sg-dbx-key', 'sg-dbx-secret'),
    'google': ('sg-gd-client.apps.googleusercontent.com', 'sg-gd-secret'),
    'microsoft': ('11111111-2222-3333-4444-555555555555', 'sg-od-secret'),
    'pcloud': ('sg-pc-client', 'sg-pc-secret'),
}
REFRESH = {'dropbox': 'rt-dropbox', 'google': 'rt-google', 'microsoft': 'rt-microsoft'}
TOKENS = {}            # access token -> provider ("pcloud-us" / "pcloud-eu" for pCloud)
for region in ('us', 'eu'):
    TOKENS['pc-token-' + region] = 'pcloud-' + region
STATS = {'refreshed': {}}


def log(*a):
    print(time.strftime('%H:%M:%S'), *a, file=sys.stderr, flush=True)


def issue(provider):
    t = provider[:3] + '-' + secrets.token_urlsafe(18)
    with LOCK:
        TOKENS[t] = provider
        STATS['refreshed'][provider] = STATS['refreshed'].get(provider, 0) + 1
    return t


# ----------------------------------------------------------------- stores ---

class Tree:
    """A tiny hierarchical file store: path (case-insensitive like Dropbox and
    OneDrive) -> {'name', 'data' or None for folders, 'id'}."""

    def __init__(self, case_insensitive=True):
        self.items = {}
        self.ci = case_insensitive
        self.next_id = 1000

    def key(self, path):
        path = '/' + path.strip('/')
        return path.lower() if self.ci else path

    def new_id(self):
        self.next_id += 1
        return self.next_id

    def get(self, path):
        return self.items.get(self.key(path))

    def put(self, path, data=None, folder=False):
        k = self.key(path)
        parent = k.rsplit('/', 1)[0] or '/'
        if parent != '/' and parent not in self.items:  # create parents, like both APIs
            self.put(path.rsplit('/', 1)[0], folder=True)
        old = self.items.get(k)
        item = {'name': path.strip('/').rsplit('/', 1)[-1], 'data': None if folder else data,
                'id': old['id'] if old else self.new_id(), 'mtime': int(time.time())}
        self.items[k] = item
        return item

    def delete(self, path):
        k = self.key(path)
        if k not in self.items:
            return False
        for kk in [x for x in self.items if x == k or x.startswith(k + '/')]:
            del self.items[kk]
        return True

    def children(self, path):
        k = self.key(path)
        if k != '/' and (k not in self.items or self.items[k]['data'] is not None):
            return None
        pre = '' if k == '/' else k
        return [v for kk, v in sorted(self.items.items())
                if kk.startswith(pre + '/') and '/' not in kk[len(pre) + 1:]]


DROPBOX = Tree()
ONEDRIVE = Tree()
PCLOUD = {'us': Tree(case_insensitive=False), 'eu': Tree(case_insensitive=False)}
DBX_SESSIONS = {}      # session_id -> bytearray
OD_SESSIONS = {}       # upload id -> {'path', 'size', 'data'}
PC_UPLOADS = {}        # uploadid -> {'region', 'data'}
PC_LINKS = {}          # path -> bytes
GD_FILES = {}          # id -> {'id','name','mimeType','parents','data','trashed'}
GD_SESSIONS = {}       # upload_id -> {'meta','size','data'}


# ---------------------------------------------------------------- handler ---

class H(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    server_version = 'fake-clouds'

    def log_message(self, fmt, *args):
        pass

    # helpers
    def body(self):
        if hasattr(self, '_body'):
            return self._body
        n = int(self.headers.get('Content-Length') or 0)
        if self.headers.get('Transfer-Encoding', '').lower() == 'chunked':
            data = bytearray()
            while True:
                size = int(self.rfile.readline().strip().split(b';')[0], 16)
                if size == 0:
                    self.rfile.readline()
                    break
                data += self.rfile.read(size)
                self.rfile.readline()
            self._body = bytes(data)
        else:
            self._body = self.rfile.read(n) if n else b''
        return self._body

    def send(self, code, body=b'', ctype='application/json', headers=None):
        if isinstance(body, (dict, list)):
            body = json.dumps(body).encode()
        elif isinstance(body, str):
            body = body.encode()
        self.send_response(code)
        if body or code not in (204, 304):
            self.send_header('Content-Type', ctype)
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(body)
        extra = ''
        if code >= 400:
            extra = ' [Content-Type: %r] %s' % (self.headers.get('Content-Type'), body[:200].decode('utf-8', 'replace'))
        log(self.command, self.headers.get('Host'), self.path[:120], '->', code, extra)

    def bearer(self):
        a = self.headers.get('Authorization', '')
        return a[7:] if a.startswith('Bearer ') else None

    def authed(self, provider):
        t = self.bearer()
        return t is not None and TOKENS.get(t) == provider

    def form(self):
        ct = self.headers.get('Content-Type', '')
        if not ct.startswith('application/x-www-form-urlencoded'):
            return None
        return dict(urllib.parse.parse_qsl(self.body().decode()))

    def route(self):
        host = (self.headers.get('Host') or '').split(':')[0].lower()
        u = urllib.parse.urlsplit(self.path)
        q = dict(urllib.parse.parse_qsl(u.query, keep_blank_values=True))
        fn = {
            'api.dropboxapi.com': self.dropbox_api,
            'content.dropboxapi.com': self.dropbox_content,
            'oauth2.googleapis.com': self.google_oauth,
            'www.googleapis.com': self.google_drive,
            'login.microsoftonline.com': self.ms_login,
            'graph.microsoft.com': self.ms_graph,
            'fake-my.sharepoint.com': self.ms_sharepoint,
            'api.pcloud.com': lambda p, q: self.pcloud('us', p, q),
            'eapi.pcloud.com': lambda p, q: self.pcloud('eu', p, q),
            'p-fake1.pcloud.com': self.pcloud_dl,
            'keystone.test': self.keystone,
            'accounts.google.com': lambda p, q: self.authorize('google', p, q),
            'www.dropbox.com': lambda p, q: self.authorize('dropbox', p, q),
            'my.pcloud.com': lambda p, q: self.authorize('pcloud', p, q),
        }.get(host)
        if fn is None:
            return self.send(421, {'error': 'unknown host ' + host})
        try:
            fn(u.path, q)
        except Exception as e:  # a fake bug must show as a failure, not hang
            log('EXC', repr(e))
            self.send(500, {'error': 'fake exception: ' + repr(e)})

    do_GET = do_POST = do_PUT = do_DELETE = do_HEAD = do_PATCH = route

    # ------------------------------------------------------- authorize pages ---

    def authorize(self, provider, path, q):
        """The consent page: approve immediately and redirect back with a code."""
        expect_path = {'google': '/o/oauth2/v2/auth', 'dropbox': '/oauth2/authorize', 'pcloud': '/oauth2/authorize',
                       'microsoft': '/oauth2/v2.0/authorize'}[provider]
        if not path.endswith(expect_path):
            return self.send(404, 'no such page', ctype='text/plain')
        cid = q.get('client_id')
        redirect = q.get('redirect_uri', '')
        if cid != CLIENTS[provider][0]:
            return self.send(400, '%s: unknown client_id %r' % (provider, cid), ctype='text/plain')
        if q.get('response_type') != 'code':
            return self.send(400, 'response_type must be code', ctype='text/plain')
        if provider == 'microsoft' and '?' in redirect:
            # learn.microsoft.com/entra/identity-platform/reply-url: query parameters are
            # not allowed for apps that sign in personal Microsoft accounts.
            return self.send(400, 'AADSTS50011: query parameters are not allowed in the redirect URI', ctype='text/plain')
        if REGISTERED_REDIRECT is None or redirect != REGISTERED_REDIRECT:
            return self.send(400, 'redirect_uri_mismatch: %r is not registered' % redirect, ctype='text/plain')
        if provider == 'microsoft' and 'offline_access' not in q.get('scope', '').split():
            return self.send(400, 'scope must include offline_access to get a refresh token', ctype='text/plain')
        if provider == 'google' and (q.get('access_type') != 'offline'):
            return self.send(400, 'access_type=offline required for a refresh token', ctype='text/plain')
        if provider == 'dropbox' and q.get('token_access_type') != 'offline':
            return self.send(400, 'token_access_type=offline required for a refresh token', ctype='text/plain')
        if not q.get('state'):
            return self.send(400, 'state missing', ctype='text/plain')
        code = provider[:2] + '-code-' + secrets.token_urlsafe(10)
        CODES[code] = {'provider': provider, 'redirect_uri': redirect, 'client_id': cid}
        back = {'code': code, 'state': q['state']}
        if provider == 'pcloud':
            back.update({'locationid': '2', 'hostname': 'eapi.pcloud.com'})  # an EU account
        sep = '&' if '?' in redirect else '?'
        return self.send(302, b'', headers={'Location': redirect + sep + urllib.parse.urlencode(back)})

    def redeem(self, provider, f):
        """Validate an authorization_code grant; returns an error string or None."""
        c = CODES.pop(f.get('code', ''), None)
        if not c or c['provider'] != provider:
            return 'invalid_grant: unknown or used code'
        if 'redirect_uri' in f and f['redirect_uri'] != c['redirect_uri']:
            return 'invalid_grant: redirect_uri does not match the authorization request'
        if provider in ('google', 'microsoft', 'dropbox') and f.get('redirect_uri') != c['redirect_uri']:
            return 'invalid_grant: redirect_uri required and must match'
        return None

    # ------------------------------------------------------------ Dropbox ---
    # https://www.dropbox.com/developers/documentation/http/documentation

    def dropbox_token(self):
        f = self.form()
        if f is None:
            return self.send(400, {'error': 'invalid_request', 'error_description': 'form body required'})
        cid, sec = f.get('client_id'), f.get('client_secret')
        if (cid, sec) != CLIENTS['dropbox']:
            return self.send(400, {'error': 'invalid_client', 'error_description': 'Invalid client_id or client_secret'})
        if f.get('grant_type') == 'authorization_code':
            e = self.redeem('dropbox', f)
            if e:
                return self.send(400, {'error': 'invalid_grant', 'error_description': e})
            return self.send(200, {'access_token': issue('dropbox'), 'token_type': 'bearer', 'expires_in': 14400,
                                   'refresh_token': REFRESH['dropbox'], 'account_id': 'dbid:fake', 'uid': '1'})
        if f.get('grant_type') != 'refresh_token' or f.get('refresh_token') != REFRESH['dropbox']:
            return self.send(400, {'error': 'invalid_grant', 'error_description': 'refresh token is malformed'})
        return self.send(200, {'access_token': issue('dropbox'), 'token_type': 'bearer', 'expires_in': 14400})

    def dbx_err(self, code, summary, tag=None):
        return self.send(code, {'error_summary': summary, 'error': tag or {'.tag': summary.split('/')[0]}})

    def dbx_bad_ctype(self, fn, got, expect):
        txt = 'Error in call to API function "%s": Bad HTTP "Content-Type" header: "%s".  Expecting one of %s.' % (
            fn, got, ', '.join('"%s"' % e for e in expect))
        return self.send(400, txt, ctype='text/plain; charset=utf-8')

    def dropbox_api(self, path, q):
        if path == '/oauth2/token':
            return self.dropbox_token()
        fn = path[3:] if path.startswith('/2/') else path
        if self.command != 'POST':
            return self.send(405, 'POST only', ctype='text/plain')
        if not self.authed('dropbox'):
            return self.send(401, {'error_summary': 'expired_access_token/', 'error': {'.tag': 'expired_access_token'}})
        ct = self.headers.get('Content-Type')
        raw = self.body()
        # RPC endpoints: JSON body, or no body and no Content-Type when there are no arguments.
        if ct is None:
            if raw:
                return self.dbx_bad_ctype(fn, '', ['application/json'])
            args = None
        else:
            if ct.split(';')[0].strip() != 'application/json':
                return self.dbx_bad_ctype(fn, ct, ['application/json', 'application/json; charset=utf-8', 'text/plain; charset=dropbox-cors-hack'])
            try:
                args = json.loads(raw or b'null')
            except ValueError:
                return self.send(400, 'Error in call to API function "%s": request body: could not decode input as JSON' % fn, ctype='text/plain')
        with LOCK:
            if fn == 'users/get_current_account':
                if args is not None:
                    return self.send(400, 'Error in call to API function "%s": request body: expected null, got value' % fn, ctype='text/plain')
                return self.send(200, {'account_id': 'dbid:fake', 'email': 'sg@example.test', 'name': {'display_name': 'SG Test'}})
            if fn == 'files/list_folder':
                p = args.get('path', '')
                kids = DROPBOX.children(p or '/')
                if kids is None:
                    return self.dbx_err(409, 'path/not_found/', {'.tag': 'path', 'path': {'.tag': 'not_found'}})
                limit = int(args.get('limit') or 2000)
                entries = [{'.tag': 'folder' if k['data'] is None else 'file', 'name': k['name'],
                            'path_display': p.rstrip('/') + '/' + k['name'], 'id': 'id:%d' % k['id'],
                            **({} if k['data'] is None else {'size': len(k['data'])})} for k in kids]
                return self.send(200, self.dbx_page(entries, limit))
            if fn == 'files/list_folder/continue':
                c = json.loads(base64.b64decode(args['cursor']))
                return self.send(200, self.dbx_page(c['rest'], c['limit']))
            if fn == 'files/delete_v2':
                p = args.get('path', '')
                item = DROPBOX.get(p)
                if not item:
                    return self.dbx_err(409, 'path_lookup/not_found/', {'.tag': 'path_lookup', 'path_lookup': {'.tag': 'not_found'}})
                DROPBOX.delete(p)
                return self.send(200, {'metadata': {'.tag': 'file', 'name': item['name']}})
        return self.send(400, 'Unknown API function: "%s"' % fn, ctype='text/plain')

    def dbx_page(self, entries, limit):
        page, rest = entries[:limit], entries[limit:]
        cursor = base64.b64encode(json.dumps({'rest': rest, 'limit': limit}).encode()).decode()
        return {'entries': page, 'cursor': cursor, 'has_more': bool(rest)}

    def dropbox_content(self, path, q):
        fn = path[3:] if path.startswith('/2/') else path
        if self.command != 'POST':
            return self.send(405, 'POST only', ctype='text/plain')
        if not self.authed('dropbox'):
            return self.send(401, {'error_summary': 'expired_access_token/', 'error': {'.tag': 'expired_access_token'}})
        argh = self.headers.get('Dropbox-API-Arg')
        if argh is None:
            return self.send(400, 'Error in call to API function "%s": Must provide HTTP header "Dropbox-API-Arg" or URL parameter "arg".' % fn, ctype='text/plain')
        try:
            argh.encode('ascii')
        except UnicodeEncodeError:
            return self.send(400, 'Dropbox-API-Arg must be ASCII; escape non-ASCII characters', ctype='text/plain')
        args = json.loads(argh)
        ct = self.headers.get('Content-Type')
        data = self.body()
        if fn == 'files/download':
            ok = (None, 'text/plain', 'text/plain; charset=utf-8', 'application/octet-stream', 'application/octet-stream; charset=utf-8')
            if ct not in ok:
                return self.dbx_bad_ctype(fn, ct, [x for x in ok if x])
            if data:
                return self.send(400, 'Error in call to API function "files/download": This function requires its argument in the HTTP header "Dropbox-API-Arg"; the request body must be empty.', ctype='text/plain')
            with LOCK:
                item = DROPBOX.get(args['path'])
            if not item or item['data'] is None:
                return self.dbx_err(409, 'path/not_found/', {'.tag': 'path', 'path': {'.tag': 'not_found'}})
            meta = json.dumps({'name': item['name'], 'size': len(item['data'])})
            return self.send(200, bytes(item['data']), ctype='application/octet-stream', headers={'Dropbox-API-Result': meta})
        # upload endpoints
        ok = ('application/octet-stream', 'text/plain; charset=dropbox-cors-hack')
        if ct not in ok:
            return self.dbx_bad_ctype(fn, ct or '', list(ok))
        with LOCK:
            if fn == 'files/upload':
                if len(data) > 150 * 1024 * 1024:
                    return self.send(400, 'request body too large; use upload sessions', ctype='text/plain')
                item = DROPBOX.put(args['path'], bytes(data))
                return self.send(200, {'name': item['name'], 'size': len(data)})
            if fn == 'files/upload_session/start':
                sid = secrets.token_hex(8)
                DBX_SESSIONS[sid] = bytearray(data)
                if args.get('close'):
                    pass
                return self.send(200, {'session_id': sid})
            if fn in ('files/upload_session/append_v2', 'files/upload_session/finish'):
                cur = args['cursor']
                buf = DBX_SESSIONS.get(cur['session_id'])
                if buf is None:
                    return self.dbx_err(409, 'lookup_failed/not_found/')
                if cur['offset'] != len(buf):
                    return self.send(409, {'error_summary': 'lookup_failed/incorrect_offset/',
                                           'error': {'.tag': 'lookup_failed', 'lookup_failed': {'.tag': 'incorrect_offset', 'correct_offset': len(buf)}}})
                if len(data) > 150 * 1024 * 1024:
                    return self.send(400, 'chunk too large', ctype='text/plain')
                buf += data
                if fn.endswith('finish'):
                    item = DROPBOX.put(args['commit']['path'], bytes(buf))
                    del DBX_SESSIONS[cur['session_id']]
                    return self.send(200, {'name': item['name'], 'size': len(buf)})
                return self.send(200, b'null')
        return self.send(400, 'Unknown API function: "%s"' % fn, ctype='text/plain')

    # ------------------------------------------------------------- Google ---
    # https://developers.google.com/drive/api/reference/rest/v3

    def google_oauth(self, path, q):
        if path != '/token' or self.command != 'POST':
            return self.send(404, {'error': 'not_found'})
        f = self.form()
        if f is None:
            return self.send(400, {'error': 'invalid_request'})
        if (f.get('client_id'), f.get('client_secret')) != CLIENTS['google']:
            return self.send(401, {'error': 'invalid_client', 'error_description': 'The OAuth client was not found.'})
        if f.get('grant_type') == 'authorization_code':
            e = self.redeem('google', f)
            if e:
                return self.send(400, {'error': 'invalid_grant', 'error_description': e})
            return self.send(200, {'access_token': issue('google'), 'expires_in': 3599, 'token_type': 'Bearer',
                                   'refresh_token': REFRESH['google'], 'scope': 'https://www.googleapis.com/auth/drive.file'})
        if f.get('grant_type') != 'refresh_token' or f.get('refresh_token') != REFRESH['google']:
            return self.send(400, {'error': 'invalid_grant', 'error_description': 'Bad Request'})
        return self.send(200, {'access_token': issue('google'), 'expires_in': 3599, 'token_type': 'Bearer',
                               'scope': 'https://www.googleapis.com/auth/drive.file'})

    def gerr(self, code, msg, reason='invalid'):
        return self.send(code, {'error': {'code': code, 'message': msg, 'errors': [{'reason': reason, 'message': msg}]}})

    def gd_query(self, qs):
        """Evaluate the subset of the Drive query language SiteGuard uses."""
        clauses = re.split(r'\s+and\s+', qs.strip())
        tests = []
        for c in clauses:
            m = re.fullmatch(r"(name|mimeType)\s*=\s*'((?:[^'\\]|\\.)*)'", c)
            if m:
                val = re.sub(r'\\(.)', r'\1', m.group(2))
                tests.append(lambda f, k=m.group(1), v=val: f[k] == v)
                continue
            m = re.fullmatch(r"'([^']*)'\s+in\s+parents", c)
            if m:
                tests.append(lambda f, p=m.group(1): p in f['parents'])
                continue
            if c == 'trashed=false' or c == 'trashed = false':
                tests.append(lambda f: not f['trashed'])
                continue
            raise ValueError('Invalid Value')
        return [f for f in GD_FILES.values() if all(t(f) for t in tests)]

    def google_drive(self, path, q):
        if path.startswith('/upload/drive/v3/files') and 'upload_id' in q:
            return self.gd_upload_put(q['upload_id'])
        if not self.authed('google'):
            return self.gerr(401, 'Request had invalid authentication credentials.', 'authError')
        with LOCK:
            if path == '/drive/v3/about':
                if 'fields' not in q:
                    return self.gerr(400, "The 'fields' parameter is required for this method.", 'required')
                return self.send(200, {'user': {'displayName': 'SG Test', 'emailAddress': 'sg@example.test'}})
            if path == '/drive/v3/files' and self.command == 'GET':
                try:
                    files = self.gd_query(q.get('q', 'trashed=false'))
                except ValueError:
                    return self.gerr(400, 'Invalid Value', 'invalid')
                size = min(int(q.get('pageSize', 100)), 1000)
                start = int(q.get('pageToken') or 0)
                page = files[start:start + size]
                out = {'files': [{'id': f['id'], 'name': f['name'], 'size': str(len(f['data'] or b''))} for f in page]}
                if start + size < len(files):
                    out['nextPageToken'] = str(start + size)
                return self.send(200, out)
            if path == '/drive/v3/files' and self.command == 'POST':
                if not (self.headers.get('Content-Type') or '').startswith('application/json'):
                    return self.gerr(400, 'Media type not supported for metadata-only create', 'badContent')
                meta = json.loads(self.body() or b'{}')
                fid = secrets.token_urlsafe(12)
                GD_FILES[fid] = {'id': fid, 'name': meta.get('name', 'Untitled'), 'mimeType': meta.get('mimeType', 'application/octet-stream'),
                                 'parents': meta.get('parents', ['root']), 'data': None, 'trashed': False}
                return self.send(200, {'kind': 'drive#file', 'id': fid, 'name': GD_FILES[fid]['name'], 'mimeType': GD_FILES[fid]['mimeType']})
            m = re.fullmatch(r'/drive/v3/files/([^/]+)', path)
            if m:
                f = GD_FILES.get(m.group(1))
                if not f:
                    return self.gerr(404, 'File not found: %s.' % m.group(1), 'notFound')
                if self.command == 'DELETE':
                    del GD_FILES[f['id']]
                    return self.send(204)
                if self.command == 'GET' and q.get('alt') == 'media':
                    return self.send(200, bytes(f['data'] or b''), ctype='application/octet-stream')
                return self.send(200, {'id': f['id'], 'name': f['name']})
            if path == '/upload/drive/v3/files' and self.command == 'POST':
                if q.get('uploadType') != 'resumable':
                    return self.gerr(400, 'unsupported uploadType in this fake')
                if not (self.headers.get('Content-Type') or '').startswith('application/json'):
                    return self.gerr(400, 'Metadata part must be application/json', 'badContent')
                meta = json.loads(self.body() or b'{}')
                size = self.headers.get('X-Upload-Content-Length')
                uid = secrets.token_urlsafe(16)
                GD_SESSIONS[uid] = {'meta': meta, 'size': int(size) if size else None, 'data': bytearray()}
                loc = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=' + uid
                return self.send(200, b'', headers={'Location': loc, 'X-GUploader-UploadID': uid})
        return self.gerr(404, 'Not Found', 'notFound')

    def gd_upload_put(self, uid):
        # https://developers.google.com/drive/api/guides/manage-uploads#resumable
        if self.command != 'PUT':
            return self.gerr(405, 'PUT expected')
        with LOCK:
            s = GD_SESSIONS.get(uid)
            if not s:
                return self.gerr(404, 'upload session not found', 'notFound')
            data = self.body()
            cr = self.headers.get('Content-Range', '')
            m = re.fullmatch(r'bytes (\d+)-(\d+)/(\d+|\*)', cr)
            if not m:
                return self.gerr(400, 'Content-Range required')
            a, b, total = int(m.group(1)), int(m.group(2)), m.group(3)
            if a != len(s['data']) or b - a + 1 != len(data):
                return self.gerr(400, 'Content-Range does not match the data received')
            final = total != '*' and b + 1 == int(total)
            if not final and len(data) % (256 * 1024):
                return self.gerr(400, 'Non-final chunk size must be a multiple of 256 KiB')
            s['data'] += data
            if not final:
                return self.send(308, b'', headers={'Range': 'bytes=0-%d' % (len(s['data']) - 1)})
            fid = secrets.token_urlsafe(12)
            GD_FILES[fid] = {'id': fid, 'name': s['meta'].get('name'), 'mimeType': 'application/octet-stream',
                             'parents': s['meta'].get('parents', ['root']), 'data': bytes(s['data']), 'trashed': False}
            del GD_SESSIONS[uid]
            return self.send(200, {'kind': 'drive#file', 'id': fid, 'name': GD_FILES[fid]['name']})

    # ---------------------------------------------------------- Microsoft ---
    # https://learn.microsoft.com/graph/api/resources/onedrive

    def ms_login(self, path, q):
        if path.endswith('/oauth2/v2.0/authorize') and self.command == 'GET':
            return self.authorize('microsoft', path, q)
        if not path.endswith('/oauth2/v2.0/token') or self.command != 'POST':
            return self.send(404, {'error': 'not_found'})
        f = self.form()
        if f is None:
            return self.send(400, {'error': 'invalid_request', 'error_description': 'AADSTS900144: body must be form-encoded'})
        if f.get('client_id') != CLIENTS['microsoft'][0]:
            return self.send(400, {'error': 'unauthorized_client', 'error_description': 'AADSTS700016: Application not found.'})
        if f.get('client_secret') != CLIENTS['microsoft'][1]:
            return self.send(401, {'error': 'invalid_client', 'error_description': 'AADSTS7000215: Invalid client secret provided.'})
        if f.get('grant_type') == 'authorization_code':
            e = self.redeem('microsoft', f)
            if e:
                return self.send(400, {'error': 'invalid_grant', 'error_description': 'AADSTS70000: ' + e})
            return self.send(200, {'token_type': 'Bearer', 'scope': 'Files.ReadWrite', 'expires_in': 3599,
                                   'access_token': issue('microsoft'), 'refresh_token': REFRESH['microsoft']})
        if f.get('grant_type') != 'refresh_token' or f.get('refresh_token') != REFRESH['microsoft']:
            return self.send(400, {'error': 'invalid_grant', 'error_description': 'AADSTS70000: The provided grant is invalid.'})
        return self.send(200, {'token_type': 'Bearer', 'scope': 'Files.ReadWrite.All', 'expires_in': 3599,
                               'access_token': issue('microsoft'), 'refresh_token': REFRESH['microsoft']})

    def merr(self, code, ecode, msg):
        return self.send(code, {'error': {'code': ecode, 'message': msg}})

    def od_item(self, path, it):
        out = {'id': 'OD%d' % it['id'], 'name': it['name']}
        if it['data'] is None:
            out['folder'] = {'childCount': len(ONEDRIVE.children(path) or [])}
        else:
            out['file'] = {}
            out['size'] = len(it['data'])
        return out

    def ms_graph(self, path, q):
        if not self.authed('microsoft'):
            return self.merr(401, 'InvalidAuthenticationToken', 'Access token has expired or is not yet valid.')
        if not path.startswith('/v1.0/'):
            return self.merr(400, 'BadRequest', 'Invalid version')
        p = path[len('/v1.0'):]
        with LOCK:
            if p == '/me/drive':
                return self.send(200, {'id': 'drive1', 'driveType': 'business', 'quota': {'total': 1 << 40}})
            if p == '/me/drive/root/children' and self.command == 'POST':
                meta = json.loads(self.body() or b'{}')
                if ONEDRIVE.get(meta['name']):
                    if meta.get('@microsoft.graph.conflictBehavior') == 'fail':
                        return self.merr(409, 'nameAlreadyExists', 'The specified item name already exists.')
                it = ONEDRIVE.put(meta['name'], folder=True)
                return self.send(201, self.od_item(meta['name'], it))
            m = re.fullmatch(r'/me/drive/root:(/[^:]*)(?::(/.*))?', p)
            if not m:
                return self.merr(400, 'invalidRequest', 'Unsupported path in fake: ' + p)
            ipath = urllib.parse.unquote(m.group(1))
            action = m.group(2) or ''
            if action == '/content' and self.command == 'PUT':
                data = self.body()
                if len(data) > 250 * 1024 * 1024:
                    return self.merr(413, 'requestTooLarge', 'Use an upload session')
                it = ONEDRIVE.put(ipath, data)
                return self.send(201, self.od_item(ipath, it))
            if action == '/content' and self.command == 'GET':
                it = ONEDRIVE.get(ipath)
                if not it or it['data'] is None:
                    return self.merr(404, 'itemNotFound', 'The resource could not be found.')
                tok = secrets.token_urlsafe(10)
                PC_LINKS['od:' + tok] = bytes(it['data'])
                return self.send(302, b'', headers={'Location': 'https://fake-my.sharepoint.com/_layouts/15/download.aspx?tempauth=' + tok})
            if action == '/createUploadSession' and self.command == 'POST':
                body = json.loads(self.body() or b'{}')
                uid = secrets.token_urlsafe(16)
                OD_SESSIONS[uid] = {'path': ipath, 'data': bytearray(), 'meta': body}
                return self.send(200, {'uploadUrl': 'https://fake-my.sharepoint.com/personal/up/' + uid,
                                       'expirationDateTime': '2099-01-01T00:00:00Z', 'nextExpectedRanges': ['0-']})
            if action == '/children' and self.command == 'GET':
                kids = ONEDRIVE.children(ipath)
                if kids is None:
                    return self.merr(404, 'itemNotFound', 'The resource could not be found.')
                skip = int(q.get('$skiptoken') or 0)
                page = kids[skip:skip + 200]
                out = {'value': [{'name': k['name']} for k in page]}
                if skip + 200 < len(kids):
                    out['@odata.nextLink'] = 'https://graph.microsoft.com/v1.0' + p + '?$select=name&$skiptoken=%d' % (skip + 200)
                return self.send(200, out)
            if action == '' and self.command == 'GET':
                it = ONEDRIVE.get(ipath)
                if not it:
                    return self.merr(404, 'itemNotFound', 'The resource could not be found.')
                return self.send(200, self.od_item(ipath, it))
            if action == '' and self.command == 'DELETE':
                if not ONEDRIVE.delete(ipath):
                    return self.merr(404, 'itemNotFound', 'The resource could not be found.')
                return self.send(204)
        return self.merr(400, 'invalidRequest', 'Unsupported in fake: %s %s' % (self.command, p))

    def ms_sharepoint(self, path, q):
        if path.startswith('/personal/up/'):
            # Pre-authenticated upload URL: "Including the Authorization header ...
            # may result in an HTTP 401 Unauthorized response."
            if self.headers.get('Authorization'):
                return self.merr(401, 'unauthenticated', 'Authorization header must not be sent to the upload URL')
            uid = path.rsplit('/', 1)[1]
            with LOCK:
                s = OD_SESSIONS.get(uid)
                if not s:
                    return self.merr(404, 'itemNotFound', 'upload session not found')
                data = self.body()
                m = re.fullmatch(r'bytes (\d+)-(\d+)/(\d+)', self.headers.get('Content-Range', ''))
                if not m:
                    return self.merr(400, 'invalidRequest', 'Content-Range required')
                a, b, total = map(int, m.groups())
                if a != len(s['data']):
                    return self.merr(416, 'invalidRange', 'Fragment overlaps or skips; expected %d' % len(s['data']))
                if b - a + 1 != len(data):
                    return self.merr(400, 'invalidRequest', 'Content-Range length mismatch')
                if len(data) > 60 * 1024 * 1024:
                    return self.merr(400, 'invalidRequest', 'Fragment larger than 60 MiB')
                final = b + 1 == total
                if not final and len(data) % (320 * 1024):
                    return self.merr(400, 'invalidRequest', 'Fragment size must be a multiple of 320 KiB')
                s['data'] += data
                if not final:
                    return self.send(202, {'expirationDateTime': '2099-01-01T00:00:00Z', 'nextExpectedRanges': ['%d-' % len(s['data'])]})
                it = ONEDRIVE.put(s['path'], bytes(s['data']))
                del OD_SESSIONS[uid]
                return self.send(201, self.od_item(s['path'], it))
        if path == '/_layouts/15/download.aspx':
            data = PC_LINKS.get('od:' + q.get('tempauth', ''))
            if data is None:
                return self.merr(401, 'unauthenticated', 'bad tempauth')
            log('onedrive download; Authorization forwarded to pre-authenticated URL:', bool(self.headers.get('Authorization')))
            return self.send(200, data, ctype='application/octet-stream')
        return self.merr(404, 'itemNotFound', 'not found')

    # ------------------------------------------------------------- pCloud ---
    # https://docs.pcloud.com/  (methods take parameters in the query string or a
    # form body; data goes up as multipart/form-data POST or as a raw-body PUT)

    def pcloud(self, region, path, q):
        def res(d, code=200):
            return self.send(code, d)

        def err(code, msg):
            return res({'result': code, 'error': msg})
        if path.strip('/') == 'oauth2_token':
            # https://docs.pcloud.com/methods/oauth_2.0/oauth2_token.html
            if (q.get('client_id'), q.get('client_secret')) != CLIENTS['pcloud']:
                return self.send(200, {'result': 2012, 'error': 'Invalid client_id or client_secret.'})
            c = CODES.pop(q.get('code', ''), None)
            if not c or c['provider'] != 'pcloud':
                return self.send(200, {'result': 2012, 'error': 'Invalid code.'})
            # Tokens belong to one data region: redeemed at the host the callback named.
            return self.send(200, {'result': 0, 'access_token': 'pc-token-' + region, 'token_type': 'bearer',
                                   'uid': 1, 'locationid': 2 if region == 'eu' else 1})
        tok = self.bearer() or q.get('access_token')
        if not tok or tok not in TOKENS or not TOKENS[tok].startswith('pcloud-'):
            return err(1000, 'Log in required.')
        if TOKENS[tok] != 'pcloud-' + region:
            return err(2094, "Invalid 'access_token' provided.")
        store = PCLOUD[region]
        ct = self.headers.get('Content-Type', '')
        params = dict(q)
        if self.command == 'POST' and ct.startswith('application/x-www-form-urlencoded'):
            params.update(dict(urllib.parse.parse_qsl(self.body().decode())))
        m = path.strip('/')
        with LOCK:
            if m == 'userinfo':
                return res({'result': 0, 'email': 'sg@example.test', 'quota': 1 << 40})

            def folder_path(fid):
                fid = int(fid)
                if fid == 0:
                    return '/'
                for k, v in store.items.items():
                    if v['id'] == fid and v['data'] is None:
                        return k
                return None
            if m == 'listfolder':
                fp = folder_path(params.get('folderid', 0))
                if fp is None:
                    return err(2005, 'Directory does not exist.')
                kids = store.children(fp) or []
                return res({'result': 0, 'metadata': {'folderid': int(params.get('folderid', 0)), 'isfolder': True, 'contents': [
                    {'name': k['name'], 'isfolder': k['data'] is None, **({'folderid': k['id']} if k['data'] is None else {'fileid': k['id'], 'size': len(k['data'])})}
                    for k in kids]}})
            if m == 'uploadfile':
                fp = folder_path(params.get('folderid', 0))
                if fp is None:
                    return err(2005, 'Directory does not exist.')
                files = []
                if self.command == 'PUT':
                    if not params.get('filename'):
                        return err(2001, 'Invalid file/folder name.')
                    files.append((params['filename'], self.body()))
                elif self.command == 'POST' and ct.startswith('multipart/form-data'):
                    msg = BytesParser(policy=HTTP_POLICY).parsebytes(b'Content-Type: ' + ct.encode() + b'\r\n\r\n' + self.body())
                    for part in msg.iter_parts():
                        fn = part.get_filename()
                        if fn is not None:
                            files.append((params.get('filename') or fn, part.get_payload(decode=True)))
                else:
                    return err(2003, 'Access denied. You do not have permissions to perform this operation.')
                ids = []
                for name, data in files:
                    it = store.put(fp.rstrip('/') + '/' + name, bytes(data))
                    ids.append(it['id'])
                return res({'result': 0, 'fileids': ids, 'metadata': [{'fileid': i} for i in ids]})
            if m == 'upload_create':
                uid = len(PC_UPLOADS) + 1
                PC_UPLOADS[uid] = {'region': region, 'data': bytearray()}
                return res({'result': 0, 'uploadid': uid})
            if m == 'upload_write':
                u = PC_UPLOADS.get(int(params.get('uploadid', 0)))
                if not u:
                    return err(1900, 'Invalid upload id.')
                if self.command != 'PUT' and not ct.startswith('multipart/form-data'):
                    # A raw POST body is not a documented way to send data.
                    return err(1004, "No data provided: send it as the body of a PUT, or as multipart/form-data POST.")
                data = self.body()
                if ct.startswith('multipart/form-data'):
                    msg = BytesParser(policy=HTTP_POLICY).parsebytes(b'Content-Type: ' + ct.encode() + b'\r\n\r\n' + data)
                    data = b''.join(p.get_payload(decode=True) for p in msg.iter_parts())
                off = int(params.get('uploadoffset', 0))
                if off > len(u['data']):
                    return err(1901, 'Invalid upload offset.')
                u['data'][off:off + len(data)] = data
                return res({'result': 0})
            if m == 'upload_save':
                u = PC_UPLOADS.pop(int(params.get('uploadid', 0)), None)
                if not u:
                    return err(1900, 'Invalid upload id.')
                fp = folder_path(params.get('folderid', 0))
                if fp is None or not params.get('name'):
                    return err(2005, 'Directory does not exist.')
                it = store.put(fp.rstrip('/') + '/' + params['name'], bytes(u['data']))
                return res({'result': 0, 'metadata': {'fileid': it['id'], 'name': it['name'], 'size': len(u['data'])}})
            if m in ('getfilelink', 'deletefile'):
                fid = int(params.get('fileid', 0))
                hit = [(k, v) for k, v in store.items.items() if v['id'] == fid and v['data'] is not None]
                if not hit:
                    return err(2009, 'File not found.')
                k, v = hit[0]
                if m == 'deletefile':
                    store.delete(k)
                    return res({'result': 0, 'metadata': {'fileid': fid, 'isdeleted': True}})
                lp = '/cfake%s/%s' % (secrets.token_hex(6), urllib.parse.quote(v['name']))
                PC_LINKS['pc:' + lp] = bytes(v['data'])
                return res({'result': 0, 'path': lp, 'hosts': ['p-fake1.pcloud.com'], 'expires': 'Sun, 01 Jan 2099 00:00:00 +0000'})
        return err(2000, 'Unknown method ' + m)

    def pcloud_dl(self, path, q):
        data = PC_LINKS.get('pc:' + path)
        if data is None:
            return self.send(410, b'link expired', ctype='text/plain')
        return self.send(200, data, ctype='application/octet-stream')

    # ----------------------------------------------------------- Keystone ---
    # Identity v3: https://docs.openstack.org/api-ref/identity/v3/  (v2.0 too).
    # Credentials are checked here, then a real token is taken from the SAIO
    # Swift (tempauth test:tester / testing) so storage calls hit real Swift.

    def swift_token(self):
        req = urllib.request.Request(SWIFT + '/auth/v1.0', headers={'X-Storage-User': 'test:tester', 'X-Storage-Pass': 'testing'})
        with urllib.request.urlopen(req, timeout=20) as r:
            tok = r.headers['X-Auth-Token']
            url = r.headers['X-Storage-Url']
        # SAIO reports its own loopback address; publish the mesh name instead.
        url = re.sub(r'^https?://[^/]+', SWIFT, url)
        return tok, url

    def keystone(self, path, q):
        if self.command != 'POST':
            return self.send(404, {'error': {'code': 404, 'title': 'Not Found'}})
        try:
            body = json.loads(self.body() or b'{}')
        except ValueError:
            return self.send(400, {'error': {'code': 400, 'message': 'Malformed JSON'}})
        if path == '/v3/auth/tokens':
            try:
                pw = body['auth']['identity']['password']['user']
                ok = body['auth']['identity']['methods'] == ['password'] and pw['name'] == 'sguser' \
                    and pw['password'] == 'sgpass' and pw['domain'].get('name', pw['domain'].get('id')) in ('Default', 'default')
                proj = body['auth']['scope']['project']
                ok = ok and proj['name'] == 'sgproject'
            except (KeyError, TypeError):
                return self.send(400, {'error': {'code': 400, 'message': 'Expecting to find identity in auth.'}})
            if not ok:
                return self.send(401, {'error': {'code': 401, 'message': 'The request you have made requires authentication.', 'title': 'Unauthorized'}})
            tok, url = self.swift_token()
            cat = [{'type': 'identity', 'name': 'keystone', 'endpoints': [{'interface': 'public', 'region': 'RegionOne', 'url': 'https://keystone.test/v3'}]},
                   {'type': 'object-store', 'name': 'swift', 'endpoints': [
                       {'interface': 'internal', 'region': 'RegionOne', 'url': 'http://10.255.255.1:8080/v1/AUTH_internal'},
                       {'interface': 'public', 'region': 'RegionTwo', 'url': 'http://10.255.255.2:8080/v1/AUTH_other'},
                       {'interface': 'public', 'region': 'RegionOne', 'url': url}]}]
            return self.send(201, {'token': {'methods': ['password'], 'expires_at': '2099-01-01T00:00:00Z', 'catalog': cat}},
                             headers={'X-Subject-Token': tok})
        if path == '/v2.0/tokens':
            try:
                a = body['auth']
                cred = a.get('passwordCredentials') or {}
                ok = cred.get('username') == 'sguser' and cred.get('password') == 'sgpass' and \
                    (a.get('tenantName') == 'sgproject' or a.get('tenantId') == 'sgproject')
            except (KeyError, TypeError, AttributeError):
                return self.send(400, {'error': {'code': 400, 'message': 'Invalid request'}})
            if not ok:
                return self.send(401, {'error': {'code': 401, 'message': 'The request you have made requires authentication.'}})
            tok, url = self.swift_token()
            cat = [{'type': 'object-store', 'name': 'swift', 'endpoints': [
                {'region': 'RegionTwo', 'publicURL': 'http://10.255.255.2:8080/v1/AUTH_other'},
                {'region': 'RegionOne', 'publicURL': url, 'internalURL': 'http://10.255.255.1:8080/v1/AUTH_internal'}]}]
            return self.send(200, {'access': {'token': {'id': tok, 'expires': '2099-01-01T00:00:00Z'}, 'serviceCatalog': cat}})
        return self.send(404, {'error': {'code': 404, 'title': 'Not Found'}})


def main():
    srv = ThreadingHTTPServer(('0.0.0.0', 443), H)
    srv.daemon_threads = True
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(CERT, KEY)
    srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
    log('fake-clouds listening on 443')
    srv.serve_forever()


if __name__ == '__main__':
    main()
