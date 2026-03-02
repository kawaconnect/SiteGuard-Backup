# Future Features

Features considered but deferred for a future release.

## 1. Restore Presets

Save named restore configurations (entity selection, search-replace rules, move-in mode) and reapply them. Useful for repeated migration workflows between the same two sites.

**Use case:** An operator who regularly syncs old-host.example to new-host.example could save a preset with the correct entity selection, search-replace pairs, and move-in mode, then reapply it with one click instead of reconfiguring each time.

**Scope:** New settings storage, preset management UI in the restore modal, WP-CLI `--preset=<name>` flag.

## 2. Scheduled Cron Backups via Key Exchange

Use the existing RSA key infrastructure to schedule automated backup+send on WP-Cron intervals. Would extend the `schedule` WP-CLI command and settings UI to include remote destinations.

**Use case:** Instead of manually triggering "Clone to Site" or "Send Backup", configure a daily cron job that automatically backs up and sends to a standby server - enabling continuous replication without manual intervention.

**Scope:** Extend `SiteGuard_Backup_Job_Scheduler` to support remote-send schedules, add destination selector to the schedule settings UI, add `schedule set --remote-destination=<id>` to WP-CLI.
