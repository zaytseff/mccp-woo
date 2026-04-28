.PHONY: build vendor help

build: ## Create plugin zip file
	@ ./build.sh

init: ## Install vendor & copy vendor assets
	@ ./vendor_update.sh

help: ## This help screen
	@echo
	@echo 'Make targets:'
	@echo
	@cat $(realpath $(firstword $(MAKEFILE_LIST))) | \
		sed -n -E 's/^([^.][^: ]+)\s*:(([^=#]*##\s*(.*[^[:space:]])\s*)|[^=].*)$$/    \1	\4/p' | \
		expand -t15
	@echo
