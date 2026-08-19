#!/bin/sh

npx wp-env run cli wp option update nextjs_revalidate-domain http://host.docker.internal:8083
npx wp-env run cli wp option update nextjs_revalidate-endpoint_path /revalidate
npx wp-env run cli wp option update nextjs_revalidate-secret my-super-secret
npx wp-env run cli wp option update nextjs_revalidate-debug --format=json '{"enable-logs":"on"}'

npx wp-env run cli wp rewrite structure /%postname%/ --hard
