#!/bin/sh
# リリースの取得 → 解析 → 変更履歴の記録を、キューが空になるまで実行する（deploy.yml と手元の計測で使う）。
#
# queue:work --stop-when-empty は「今すぐ実行できるジョブ」がなくなった時点で終わる。
# レート制限や重なり防止で後回しにされたジョブ（available_at が未来）が残っていても終わるので、
# jobs テーブルが空になるまで繰り返す。失敗したジョブがあれば 1 で終わる（中途半端なデータを配信しないため）。
#
# 使い方: scripts/generate-data.sh   （SQLite のパスは DB_DATABASE、既定は database/database.sqlite）
set -eu

db_file="${DB_DATABASE:-database/database.sqlite}"

count() {
  php -r '$db = new PDO("sqlite:".$argv[1]); echo $db->query("SELECT COUNT(*) FROM ".$argv[2])->fetchColumn();' "$db_file" "$1"
}

[ -f "$db_file" ] || touch "$db_file"
php artisan migrate --force --no-interaction
php artisan since:sync --no-interaction

while :; do
  php artisan queue:work --stop-when-empty --sleep=1 --no-interaction
  pending="$(count jobs)"
  if [ "$pending" -eq 0 ]; then
    break
  fi
  echo "${pending} job(s) are waiting (rate limit or overlap). Retrying in 5 seconds."
  sleep 5
done

failed="$(count failed_jobs)"
if [ "$failed" -ne 0 ]; then
  echo "::error::${failed} job(s) failed. The site is not built from incomplete data."
  php artisan queue:failed --no-interaction
  exit 1
fi
