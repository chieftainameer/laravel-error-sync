#!/bin/bash
# relay-server/install.sh
echo "Installing Error Sync Relay Server dependencies..."
pip3 install -r "$(dirname "$0")/requirements.txt"
echo "Done! Run: python3 $(dirname "$0")/error-relay.py"