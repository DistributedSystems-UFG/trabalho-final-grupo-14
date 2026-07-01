#!/usr/bin/env python3
import os
import platform
import subprocess
import sys


def main() -> int:
    project_dir = os.path.dirname(os.path.abspath(__file__))
    scripts_dir = os.path.join(project_dir, "scripts")

    system = platform.system().lower()
    if "windows" in system:
        executor = os.path.join(scripts_dir, "executor_windows.ps1")
        command = ["powershell", "-ExecutionPolicy", "Bypass", "-File", executor]
    else:
        executor = os.path.join(scripts_dir, "executor_unix.sh")
        command = ["bash", executor]

    if not os.path.exists(executor):
        print(f"Erro: executor nao encontrado: {executor}")
        return 1

    try:
        result = subprocess.run(command, cwd=project_dir)
        return result.returncode
    except KeyboardInterrupt:
        print("\nEncerrado pelo usuario.")
        return 130
    except Exception as exc:
        print(f"Erro ao executar launcher: {exc}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
