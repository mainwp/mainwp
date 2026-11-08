#!/usr/bin/env bash
redcheck_status() {
    local ok="$1" miss="$2" total="$3" expected="$4" suite_status="$5"
    [ "$miss" -eq 0 ] && [ "$ok" -eq "$expected" ] && [ "$total" -eq "$expected" ] && [ "$suite_status" -eq 0 ]
}
