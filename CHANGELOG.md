# Changelog

All notable changes to this project will be documented in this file. See [commit-and-tag-version](https://github.com/absolute-version/commit-and-tag-version) for commit guidelines.

## [1.2.9](https://github.com/prep-your-meal/backend/compare/v1.2.8...v1.2.9) (2026-07-29)

## [1.2.8](https://github.com/prep-your-meal/backend/compare/v1.2.7...v1.2.8) (2026-07-25)

## [1.2.7](https://github.com/prep-your-meal/backend/compare/v1.2.6...v1.2.7) (2026-07-25)

## [1.2.6](https://github.com/prep-your-meal/backend/compare/v1.2.5...v1.2.6) (2026-07-25)


### Bug Fixes

* **ci:** fix missing autoload.php by reordering composer step ([541be23](https://github.com/prep-your-meal/backend/commit/541be233c40105df48b921c90f48fb1ddee12f7b))

## [1.2.5](https://github.com/prep-your-meal/backend/compare/v1.2.4...v1.2.5) (2026-07-25)

## [1.2.4](https://github.com/prep-your-meal/backend/compare/v1.2.3...v1.2.4) (2026-07-25)


### Bug Fixes

* **core:** resolve static analysis errors and missing type hints ([bedaea9](https://github.com/prep-your-meal/backend/commit/bedaea965cd030f1015e2bde82b551802ae1a618))

## [1.2.3](https://github.com/prep-your-meal/backend/compare/v1.2.2...v1.2.3) (2026-07-24)


### Bug Fixes

* **swagger:** finally hide topbar via css to prevent definition error ([f76128e](https://github.com/prep-your-meal/backend/commit/f76128ea7f0fb3ae938e027491391cf8a305a1a8))

## [1.2.2](https://github.com/prep-your-meal/backend/compare/v1.2.1...v1.2.2) (2026-07-24)


### Bug Fixes

* **swagger:** restore StandaloneLayout and hide topbar via css to prevent definition error ([a8d1e9d](https://github.com/prep-your-meal/backend/commit/a8d1e9d9869f704e759aaeed4395becfd48e00d9))

## [1.2.1](https://github.com/prep-your-meal/backend/compare/v1.2.0...v1.2.1) (2026-07-24)

## [1.2.0](https://github.com/prep-your-meal/backend/compare/v1.1.0...v1.2.0) (2026-07-24)


### Features

* customize swagger ui theme and hide server signatures in htaccess ([b17b210](https://github.com/prep-your-meal/backend/commit/b17b210a0c72cfc1544bc0b4e1362eaa6d5dda4e))

## [1.1.0](https://github.com/prep-your-meal/backend/compare/v1.0.2...v1.1.0) (2026-07-24)


### Features

* use dynamic API_VERSION constant for swagger ui and root endpoint ([ba58e79](https://github.com/prep-your-meal/backend/commit/ba58e79cded7ed5ea293af9bc94a515eb284d745))

## [1.0.2](https://github.com/prep-your-meal/backend/compare/v1.0.1...v1.0.2) (2026-07-24)


### Bug Fixes

* move root endpoint back to api routes to restore /api prefix ([1897f8c](https://github.com/prep-your-meal/backend/commit/1897f8c192e9b349dc8986a8a2e89ad312e6ddb2))

## 1.0.1 (2026-07-24)


### Bug Fixes

* adjust health endpoint path and fix swagger ui assets ([a5c01a3](https://github.com/prep-your-meal/backend/commit/a5c01a30753618caf8a4888962646f56345d5c3d))
* **deploy:** remove generated api-docs from git and clean before reset ([bfce4dc](https://github.com/prep-your-meal/backend/commit/bfce4dc2190ba4b31160cdf4c40f71d3f16a4a35))
* **routing:** force root url and https for production environment ([7def0c4](https://github.com/prep-your-meal/backend/commit/7def0c45d6f948cf6095e9079dde3c78a109f903))
* **routing:** restore default api route prefix ([800a358](https://github.com/prep-your-meal/backend/commit/800a358ad3a1c903fb52fe49c87a11fed5d0832f))
* **swagger:** manually route swagger assets and docs to fix strato subfolder mapping ([db52b11](https://github.com/prep-your-meal/backend/commit/db52b11fa785e4639651c76afb2958624fbd5bbc))
* **swagger:** physically copy and serve json docs to bypass strato restrictions ([572be6e](https://github.com/prep-your-meal/backend/commit/572be6e4d257456e699ea23ea5ec8ae1352c1dc3))
