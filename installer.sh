#!/bin/bash
php s3build app:build --build-version=0.1
mv builds/s3build /usr/local/bin/s3build
chmod +x /usr/local/bin/s3build