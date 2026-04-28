#!/bin/bash
TAG=$([[ -d .git && -n $(git tag --points-at HEAD) ]] && echo $(git tag --points-at HEAD) || echo $(git rev-parse --short HEAD ))
if [[ -n "$1" ]]; then
  TAG=$1
fi

# Clear previous build if set
rm -rf ./multi-crypto-currency-payment multi-crypto-currency-payment.${TAG}.zip
mkdir ./multi-crypto-currency-payment

cp -R -t ./multi-crypto-currency-payment ./assets ./inc ./vendor LICENSE.txt mccp.php readme.txt
zip -r ./multi-crypto-currency-payment.${TAG}.zip ./multi-crypto-currency-payment
# rm -rf ./multi-crypto-currency-payment

