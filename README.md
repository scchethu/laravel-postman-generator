# 🚀 Laravel Postman Collection Generator

![Laravel Version](https://img.shields.io/badge/Laravel-10.x%20|%2011.x%20|%2012.x-red.svg)
![Packagist](https://img.shields.io/badge/Packagist-scchethu%2Flaravel--postman--generator-blue)
![License](https://img.shields.io/badge/License-MIT-green.svg)
![Status](https://img.shields.io/badge/Status-Active-success)
![PRs Welcome](https://img.shields.io/badge/PRs-Welcome-brightgreen)

A lightweight package that automatically generates a **Postman Collection (v2.1)** from your Laravel routes, making API documentation effortless and consistent.

If you build APIs in Laravel, this tool saves hours of manual Postman setup.  
Just run one command → import JSON → done!

---

# 🌟 Features

- 🔍 Scans **all registered Laravel routes**
- 📄 Generates **Postman Collection v2.1**
- ⚙ Supports custom **collection name & domain**
- 🗂 Uses Postman's `{{base_url}}` variable
- 📂 Configurable output folder & filename
- 🎯 One-command usage
- ⚡ Zero configuration required to start

---

# 📦 Installation

### Install via Composer

```bash
composer require scchethu/laravel-postman-generator
