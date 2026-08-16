#!/bin/bash
set -e

# fpp-ChannelEKG install script

BASEDIR=$(dirname $0)
cd $BASEDIR
cd ..
make "SRCDIR=${SRCDIR}"

# This branch targets FPP 8.x/9.x's libhttpserver plugin API, which has no
# hot-unload contract - fppd needs an explicit restart to pick up a fresh
# install, uninstall, or upgrade of this plugin.
