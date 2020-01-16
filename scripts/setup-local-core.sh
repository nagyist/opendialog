#!/bin/bash
echo "Clearing vendor directory"
rm -rf ./vendor
rm -rf ./vendor-local

LOCAL_VENDOR_DIR="vendodr-local"
LOCAL_CORE_DIR="vendor-local/opendialog-core"
if [ ! -d "./$LOCAL_VENDOR_DIR" ]; then
  echo "Creating local vendor directory"
  mkdir "./$LOCAL_VENDOR_DIR"
fi
if [ ! -d "./$LOCAL_CORE_DIR" ]; then
  echo "CORE::: Cloning opendialog-core to vendor-local"
  git clone git@github.com:opendialogai/core.git vendor-local/opendialog-core
  cd $LOCAL_CORE_DIR
  echo "CORE::: Installing composer"
  composer install
  cd ../..
fi
pwd
echo "OPENDIALOG::: Installing composer with symlink"
export COMPOSER=composer-dev.json
composer update
