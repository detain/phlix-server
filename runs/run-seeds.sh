#!/usr/bin/env bash
# S266 skip-count measurement harness (lane s266srv, token S266SKIPDETERMX9B3)
# Usage: run-seeds.sh <stage: before|after|control> <seed> [seed...]
# Resumable: a seed already recorded in RESULTS.tsv for that stage is skipped.
# Serialises via flock. Private TMPDIR. Default (swoole-intact) ini — NEVER hermetic scan dir.
set -u
SBX="/home/sites/phlix/.sandboxes/s266srv/phlix-server"
STAGE="$1"; shift
cd "$SBX" || exit 1
mkdir -p runs/tmp
export TMPDIR="$SBX/runs/tmp"
exec 9>runs/.harness.lock
flock -n 9 || { echo "another harness run holds the lock; exiting"; exit 2; }

parse_skips() { # $1=xml $2=out
  php -r '$d=new DOMDocument();$d->load($argv[1]);foreach((new DOMXPath($d))->query("//testcase[skipped]") as $t){echo $t->getAttribute("class"),"::",$t->getAttribute("name"),PHP_EOL;}' "$1" | sort > "$2"
}

for SEED in "$@"; do
  if grep -qF "$STAGE/$SEED" runs/RESULTS.tsv 2>/dev/null; then
    echo "[$STAGE seed=$SEED] already recorded; skipping"
    continue
  fi
  START=$(date +%s)
  php vendor/bin/phpunit --no-coverage --order-by random --random-order-seed "$SEED" \
    --log-junit "runs/$STAGE-$SEED.xml" > "runs/$STAGE-$SEED.log" 2>&1
  EXIT=$?
  parse_skips "runs/$STAGE-$SEED.xml" "runs/$STAGE-$SEED.skips.txt"
  COUNT=$(wc -l < "runs/$STAGE-$SEED.skips.txt")
  DUR=$(( $(date +%s) - START ))
  SIX=0
  for NAME in HlsRelayManagerTest::testStartRelaySessionCreatesTuneRequest HlsRelayManagerTest::testStartRelaySessionStoresInDb HlsRelayManagerTest::testStopRelaySessionDropsPerSessionSegmentCache HlsSegmentPrefetcherTest::testStartPrefetchDoesNotThrow HlsSegmentPrefetcherTest::testStartAndStopPrefetch HlsSegmentPrefetcherTest::testMultipleStartPrefetchReplacesPrevious; do
    grep -qF "$NAME" "runs/$STAGE-$SEED.skips.txt" && SIX=$((SIX+1))
  done
  SUM=$(grep -E '^OK|^Tests:|^FAILURES|Time: ' "runs/$STAGE-$SEED.log" | tr '\n' '|')
  printf "%s\t%s\tcount=%s\tsix_skipped=%s\tphpunit_exit=%s\tsecs=%s\tsummary=%s\n" \
    "$STAGE" "$SEED" "$COUNT" "$SIX" "$EXIT" "$DUR" "$SUM" >> runs/RESULTS.tsv
  git add runs/RESULTS.tsv "runs/$STAGE-$SEED.skips.txt"
  git -c core.editor=true commit -q -m "S266 $STAGE measurement: seed $SEED (skips=$COUNT six_skipped=$SIX exit=$EXIT)" || echo "commit failed"
  git push -q origin s266srv-s266-skip-determinism || echo "push failed"
  echo "[$STAGE seed=$SEED] done count=$COUNT six_skipped=$SIX exit=$EXIT ${DUR}s"
done
