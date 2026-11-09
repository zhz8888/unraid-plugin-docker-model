# Convenience targets. The real work lives in scripts/.
SHELL := /bin/bash

PLUGIN_NAME := unraid-plugin-docker-model

# Version of this plugin: the release date, YYYYMMDD. A second release on the
# same day adds a letter suffix, YYYYMMDD.a, YYYYMMDD.b and so on. The
# Unraid installer compares plugin versions with strcmp(), a byte wise string
# compare, so this format is what sorts in release order. Pin it to keep a
# build reproducible.
PLUGIN_VERSION ?= $(shell date +%Y%m%d)

# Version of the upstream docker-model-plugin release that is bundled.
DMR_VERSION ?= 1.2.9
DMR_ARCH    ?= amd64

DIST_DIR := dist
TXZ      := $(DIST_DIR)/$(PLUGIN_NAME)-$(PLUGIN_VERSION)-$(if $(filter amd64,$(DMR_ARCH)),x86_64,$(DMR_ARCH))-1.txz
PLG      := $(DIST_DIR)/$(PLUGIN_NAME).plg
ICON     := src/usr/local/emhttp/plugins/$(PLUGIN_NAME)/$(PLUGIN_NAME).png

.PHONY: all build lint verify fetch icon clean distclean release help

all: build lint verify

## build: download, verify and package the plugin
build:
	PLUGIN_VERSION=$(PLUGIN_VERSION) DMR_VERSION=$(DMR_VERSION) DMR_ARCH=$(DMR_ARCH) ./scripts/build-plugin.sh

## lint: static checks on the generated .plg and on the WebGUI sources
lint:
	./scripts/lint-plg.sh
	./scripts/check-php-syntax.py src/
	./scripts/check-locales.py src/usr/local/emhttp/plugins/unraid-plugin-docker-model/locales

## verify: verify artifact hashes and layout
verify:
	./scripts/verify-release.sh

## fetch: download and unpack the deb only (audit/debug)
fetch:
	DMR_VERSION=$(DMR_VERSION) DMR_ARCH=$(DMR_ARCH) ./scripts/fetch-plugin.sh

## icon: regenerate the plugin icon
icon:
	python3 scripts/make-icon.py $(ICON)

## release: print the gh command to publish the current build
release: build lint verify
	@echo
	@echo "Artifacts ready:"
	@echo "  $(TXZ)"
	@echo "  $(PLG)"
	@echo "  $(DIST_DIR)/$(PLUGIN_NAME)-proxy.plg"
	@echo
	@echo "Publish with:"
	@echo "  gh release create $(PLUGIN_VERSION) $(TXZ) \\"
	@echo "    --title \"$(PLUGIN_NAME) $(PLUGIN_VERSION)\" \\"
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
