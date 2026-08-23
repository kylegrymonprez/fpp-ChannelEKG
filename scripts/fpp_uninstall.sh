#!/bin/bash

# fpp-ChannelEKG uninstall script

# This branch targets FPP 8.x/9.x's libhttpserver plugin API, which has no
# hot-unload contract - fppd needs an explicit restart to actually drop this
# plugin after these files are removed.
source ${FPPDIR}/scripts/common
setSetting restartFlag 1
