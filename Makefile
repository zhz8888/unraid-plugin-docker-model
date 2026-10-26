# Convenience targets. The real work lives in scripts/.
SHELL := /bin/bash

PLUGIN_NAME := unraid-plugin-docker-model
DMR_VERSION ?= 1.2.9
DMR_ARCH    ?= amd64

DIST_DIR := dist
TXZ      := $(DIST_DIR)/$(PLUGIN_NAME)-$(DMR_VERSION)-$(if $(filter amd64,$(DMR_ARCH)),x86_64,$(DMR_ARCH))-1.txz
PLG      := $(DIST_DIR)/$(PLUGIN_NAME).plg

.PHONY: all build lint verify fetch clean distclean release help

all: build lint verify

## build: download, verify and package the plugin
build:
	DMR_VERSION=$(DMR_VERSION) DMR_ARCH=$(DMR_ARCH) ./scripts/build-plugin.sh

## lint: static checks on the generated .plg
lint:
	./scripts/lint-plg.sh

## verify: verify artifact hashes and layout
verify:
	./scripts/verify-release.sh

## fetch: download and unpack the deb only (audit/debug)
fetch:
	DMR_VERSION=$(DMR_VERSION) DMR_ARCH=$(DMR_ARCH) ./scripts/fetch-plugin.sh

## release: print the gh command to publish the current build
release: build lint verify
	@echo
	@echo "Artifacts ready:"
	@echo "  $(TXZ)"
	@echo "  $(PLG)"
	@echo
	@echo "Publish with:"
	@echo "  gh release create $(DMR_VERSION) $(TXZ) \\"
	@echo "    --title \"$(PLUGIN_NAME) $(DMR_VERSION)\" \\"
	@echo "    --notes \"See CHANGELOG.md\""

## clean: remove build intermediates
clean:
	rm -rf pkg

## distclean: remove build intermediates and artifacts
distclean: clean
	rm -rf $(DIST_DIR)

## help: list targets
help:
	@grep -E '^## ' $(MAKEFILE_LIST) | sed 's/## /  /'
