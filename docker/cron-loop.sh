#!/bin/sh
# Periodic jobs for a compose stack that has no host crontab.
# Intervals match the documentation defaults; override with env if needed.
set -eu

run() {
  echo "[click-cron] $(date -u +%Y-%m-%dT%H:%M:%SZ) $*"
  php "$@" || echo "[click-cron] failed: $* (exit $?)"
}

# Schedule first — embargoed pages go live on time.
# Webhooks next — delivery queue drains.
# Updates on a slower cadence — signed feed check.
# Backup last — least urgent of the four.
while true; do
  run /var/www/html/bin/click-schedule.php
  run /var/www/html/bin/click-webhooks.php
  # Update and backup less often: every ~6 cycles (~30 min if SLEEP=300).
  CYCLE=$(( ${CYCLE:-0} + 1 ))
  if [ $(( CYCLE % 6 )) -eq 0 ]; then
    run /var/www/html/bin/click-update.php
    run /var/www/html/bin/click-backup.php
  fi
  sleep "${CLICK_CRON_SLEEP:-300}"
done
