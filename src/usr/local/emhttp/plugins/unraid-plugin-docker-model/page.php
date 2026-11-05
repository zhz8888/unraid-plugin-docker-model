<?php
/**
 * Entry point of the unraid-plugin-docker-model WebGUI page.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 *
 * The Unraid WebGUI discovers the page through the .page files in this
 * directory. This file makes the same content reachable directly at
 * /plugins/unraid-plugin-docker-model/page.php, which is what the
 * "launch" attribute of the plugin definition points at.
 */

require_once __DIR__ . '/include/common.php';
require_once __DIR__ . '/include/Pages/Status.php';
