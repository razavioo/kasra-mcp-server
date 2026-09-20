#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd "$DIR"
exec /Library/Frameworks/Python.framework/Versions/3.12/bin/python3 main.py
