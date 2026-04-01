TAG := $(shell test -d .git && git tag --points-at HEAD)

zip: clear
	mkdir ./multi-crypto-currency-payment
	cp -R -t ./multi-crypto-currency-payment ./assets ./inc ./vendor LICENSE.txt mccp.php readme.txt
	zip -r ./multi-crypto-currency-payment.${TAG}.zip ./multi-crypto-currency-payment
	rm -rf ./multi-crypto-currency-payment

help: about

about:
	@echo "Makefile to help create .zip file"

clear:
	rm -f ./multi-crypto-currency-payment*

.PHONY: about help zip clear
