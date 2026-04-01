.PHONY: build vendor help

build: ## Create plugin zip file
	@ /bin/bash ./build.sh

vendor: ## Install or update vendor dependencies
	@if [ ! -d './vendor' ]; then \
		composer install --ignore-platform-reqs; \
	else \
		composer update --ignore-platform-reqs; \
	fi

help: ## This help screen
	@echo
	@echo 'Make targets:'
	@echo
	@cat $(realpath $(firstword $(MAKEFILE_LIST))) | \
		sed -n -E 's/^([^.][^: ]+)\s*:(([^=#]*##\s*(.*[^[:space:]])\s*)|[^=].*)$$/    \1	\4/p' | \
		expand -t15
	@echo
