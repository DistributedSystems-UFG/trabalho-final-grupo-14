#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

echo "Smart Building IoT - Executor Unix"

if [[ ! -f .env && -f .env.example ]]; then
  echo "Arquivo .env nao encontrado. Copiando .env.example..."
  cp .env.example .env
  echo "Edite o arquivo .env com os hosts/ports da sua EC2 antes de subir em producao."
fi

if command -v python3 >/dev/null 2>&1; then
  python3 start.py
elif command -v python >/dev/null 2>&1; then
  python start.py
else
  echo "Python nao encontrado. Instale Python 3 e tente novamente." >&2
  exit 1
fi
