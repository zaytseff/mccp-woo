#!/bin/bash

rm -rf ./vendor
composer update --no-cache --ignore-platform-reqs

mv ./assets ./tmp
rm -rf ./assets && mkdir ./assets
cp -r ./tmp/mccp* ./assets/
rm -rf ./tmp

cp -r ./vendor/apirone/apirone-sdk-php/src/assets/img ./assets/
cp ./vendor/apirone/apirone-sdk-php/src/assets/script.min.js ./assets/script.min.js
cp ./vendor/apirone/apirone-sdk-php/src/assets/style.min.css ./assets/style.min.css
rm -rf ./vendor/apirone/apirone-sdk-php/src/assets
