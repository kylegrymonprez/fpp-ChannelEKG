SRCDIR ?= /opt/fpp/src
include ${SRCDIR}/makefiles/common/setup.mk
include $(SRCDIR)/makefiles/platform/*.mk

all: libfpp-ChannelEKG.$(SHLIB_EXT)
debug: all

OBJECTS_fpp_ChannelEKG_so += src/FPPChannelEKG.o
LIBS_fpp_ChannelEKG_so += -L${SRCDIR} -lfpp -ljsoncpp
CXXFLAGS_src/FPPChannelEKG.o += -I${SRCDIR}

# Plugin API 6 (first shipped in FPP 10.0-beta5) replaced the libhttpserver
# based registerApis(httpserver::webserver*) with a no-arg registerApis()
# routed through FPPPlugins::registerPluginApi() in fpphttp.h, and later
# builds dropped the libhttpserver signature (and the httpserver.hpp
# dependency) entirely. Detect which one this SRCDIR actually has - by
# grepping ITS headers, not by version number - so the same source compiles
# against 8.x/9.x, the migration window, and 10.0-beta5+.
HAVE_NOARG_REGISTER_APIS := $(shell grep -qE 'virtual void registerApis\(\)' ${SRCDIR}/Plugin.h 2>/dev/null && grep -q 'registerPluginApi' ${SRCDIR}/fpphttp.h 2>/dev/null && echo 1)
ifeq ($(HAVE_NOARG_REGISTER_APIS),1)
CXXFLAGS_src/FPPChannelEKG.o += -DCEKG_HAVE_NOARG_REGISTER_APIS
endif

%.o: %.cpp Makefile
	$(CCACHE) $(CC) $(CFLAGS) $(CXXFLAGS) $(CXXFLAGS_$@) -c $< -o $@

libfpp-ChannelEKG.$(SHLIB_EXT): $(OBJECTS_fpp_ChannelEKG_so) ${SRCDIR}/libfpp.$(SHLIB_EXT)
	$(CCACHE) $(CC) -shared $(CFLAGS_$@) $(OBJECTS_fpp_ChannelEKG_so) $(LIBS_fpp_ChannelEKG_so) $(LDFLAGS) -o $@

clean:
	rm -f libfpp-ChannelEKG.so $(OBJECTS_fpp_ChannelEKG_so)
