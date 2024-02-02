#!/bin/sh

yarn wp-env run cli wp option update nextjs_revalidate-url http://host.docker.internal:8083/revalidate
yarn wp-env run cli wp option update nextjs_revalidate-secret my-super-secret
yarn wp-env run cli wp option update nextjs_revalidate-debug --format=json '{"enable-logs":"on"}'

yarn wp-env run cli wp rewrite structure /%postname%/ --hard
