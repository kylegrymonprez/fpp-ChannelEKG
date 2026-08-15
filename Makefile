SRCDIR ?= /opt/fpp/src
include ${SRCDIR}/makefiles/common/setup.mk
include $(SRCDIR)/makefiles/platform/*.mk

all: libfpp-ChannelEKG.$(SHLIB_EXT)
debug: all

OBJECTS_fpp_ChannelEKG_so += src/FPPChannelEKG.o
LIBS_fpp_ChannelEKG_so += -L${SRCDIR} -lfpp -ljsoncpp
CXXFLAGS_src/FPPChannelEKG.o += -I${SRCDIR}

%.o: %.cpp Makefile
	$(CCACHE) $(CC) $(CFLAGS) $(CXXFLAGS) $(CXXFLAGS_$@) -c $< -o $@

libfpp-ChannelEKG.$(SHLIB_EXT): $(OBJECTS_fpp_ChannelEKG_so) ${SRCDIR}/libfpp.$(SHLIB_EXT)
	$(CCACHE) $(CC) -shared $(CFLAGS_$@) $(OBJECTS_fpp_ChannelEKG_so) $(LIBS_fpp_ChannelEKG_so) $(LDFLAGS) -o $@

clean:
	rm -f libfpp-ChannelEKG.so $(OBJECTS_fpp_ChannelEKG_so)
