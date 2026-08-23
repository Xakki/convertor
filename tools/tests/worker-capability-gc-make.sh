#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

default_output=$(make --no-print-directory -n -C "$ROOT" TEST=1 worker-capability-gc)
override_output=$(make --no-print-directory -n -C "$ROOT" TEST=1 worker-capability-gc TTL_HOURS=72)

[[ $default_output == *'docker exec xakki-convertor-test-php php bin/console app:worker-capability:gc'* ]] \
    || fail 'default должен использовать тестовый PHP-контейнер'
[[ $default_output != *'--ttl-hours='* ]] \
    || fail 'default не должен передавать пустой TTL override'
[[ $default_output != *'xakki-convertor-php'* ]] \
    || fail 'default не должен использовать production-контейнер'
[[ $override_output == *'docker exec xakki-convertor-test-php php bin/console app:worker-capability:gc --ttl-hours=72'* ]] \
    || fail 'TTL_HOURS override должен использовать тестовый PHP-контейнер'
[[ $override_output != *'xakki-convertor-php'* ]] \
    || fail 'TTL_HOURS override не должен использовать production-контейнер'

printf '%s\n' 'worker-capability-gc Make contract: ok'
