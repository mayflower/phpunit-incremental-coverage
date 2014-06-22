#!/bin/bash

SCRIPT_PATH=$(readlink -e "$0")
SCRIPT_DIR=$(dirname "$SCRIPT_PATH")
FIXTURES_DIR=$(readlink -e "$SCRIPT_DIR/../test/fixtures")
PROJECT_DIR=$(readlink -e "$SCRIPT_DIR/../project")

cd "$PROJECT_DIR"
git checkout master
REVS=$(git rev-list --reverse HEAD ^c5f15aceb8bad4a74a3b33f1ddf13774531ae814)

for rev in ${REVS}; do
    git checkout -q -f ${rev}
    rm -f ${PROJECT_DIR}/app/coverage-*
    php ${SCRIPT_DIR}/CoverageAutomation.php
#    sed -i '/"HEAD"/d' ${SCRIPT_DIR}/CoverageAutomation.json
#    sed -i '/"master"/d' ${SCRIPT_DIR}/CoverageAutomation.json
    mv ${PROJECT_DIR}/app/coverage-*.xml ${FIXTURES_DIR}/${rev}.xml
    mv ${PROJECT_DIR}/app/coverage-*.serialized ${FIXTURES_DIR}/${rev}.serialized
    sed -i 's/generated\="[0-9]\+"/generated="1"/' ${FIXTURES_DIR}/${rev}.xml
    sed -i 's/timestamp\="[0-9]\+"/timestamp="1"/' ${FIXTURES_DIR}/${rev}.xml
#    rm -f ${PROJECT_DIR}/app/coverage-*.xml
#    rm -f ${PROJECT_DIR}/app/coverage-*.serialized
done
