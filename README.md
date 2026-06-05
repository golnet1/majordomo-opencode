# opencode — AI-ассистент для Majordomo

Модуль интеграции [opencode](https://opencode.ai) с системой умного дома [Majordomo](https://mjdm.ru).

## Возможности

- AI-чат в админке Majordomo
- Поддержка любых LLM-провайдеров (Ollama, OpenAI, etc.)
- Управление устройствами через MCP (Model Context Protocol)
- Выполнение bash-команд, работа с файлами
- Сохранение истории диалогов
- Статус-индикаторы подключения (API, бинарник, sudo, модель)
- Ограничения безопасности для гостевого доступа

## Требования

- Majordomo (любая версия с поддержкой модулей)
- PHP 8.2+
- Node.js 18+ (для opencode binary)
- curl, sudo (настроенный без пароля)

## Установка

1. Скопировать папки `modules/opencode`, `templates/opencode`, `languages/`, `img/` в соответствующие директории Majordomo
2. В админке Majordomo: Панель управления → Модули → установить `opencode`
3. Настроить провайдера и модель в админке модуля

---

# opencode — AI Assistant for Majordomo

Integration module for [opencode](https://opencode.ai) with the [Majordomo](https://mjdm.ru) smart home system.

## Features

- AI chat in Majordomo admin panel
- Support for any LLM providers (Ollama, OpenAI, etc.)
- Device control via MCP (Model Context Protocol)
- Bash command execution, file operations
- Chat history persistence
- Connection status indicators (API, binary, sudo, model)
- Security restrictions for guest access

## Requirements

- Majordomo (any version with module support)
- PHP 8.2+
- Node.js 18+ (for opencode binary)
- curl, sudo (passwordless configuration)

## Installation

1. Copy `modules/opencode`, `templates/opencode`, `languages/`, `img/` to your Majordomo directories
2. In Majordomo admin: Control Panel → Modules → install `opencode`
3. Configure provider and model in the module admin page
