#!/usr/bin/env bash
set -e

MY_REPO_URL="https://github.com/nam977/IT490-MAIN-REPO.git"

# Shallow clones to keep download small
if [ ! -d "database-mq" ]; then
  git clone --branch database-mq --depth 1 "$MY_REPO_URL" database-mq
fi

if [ ! -d "dmz" ]; then
  git clone --branch dmz --depth 1 "$MY_REPO_URL" dmz
fi

if [ ! -d "frontend" ]; then
  git clone --branch frontend --depth 1 "$MY_REPO_URL" frontend
fi

