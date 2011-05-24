# FOSSology Makefile - lib/libfossology
# Copyright (C) 2011 Hewlett-Packard Development Company, L.P.
TOP=../../..
VARS=$(TOP)/Makefile.conf
include $(VARS)
CFLAGS_LOCAL=-fPIC $(ALL_CFLAGS)

CONFDEF=-DFOSSDB_CONF='"$(SYSCONFDIR)/$(PROJECT)/Db.conf"' -DFOSSREPO_CONF='"$(SYSCONFDIR)/fossology"' -DFOSSGROUP='"$(PROJECTGROUP)"'
EXE=reppath repwrite repcat repexist repcopyin repremove rephost repmmapcheck sqlCopyTest
LIB=libfossology.a
OBJS=libfossscheduler.o libfossdb.o libfossagent.o libfossrepo.o sqlCopy.o

all: $(LIB) $(EXE) $(VARS)
	echo "make $(LIB)"

$(LIB): $(OBJS) $(VARS)
	$(AR) cr $@ $(OBJS)
#	$(CC) -c $< $(CONFDEF) -fPIC $(ALL_CFLAGS)

$(EXE): %: %.c $(LIB) $(VARS)
	$(CC) $< $(ALL_CFLAGS) $(LIB) -lpq -o $@

%.o: %.c %.h
	$(CC) -c $< $(CONFDEF)  -I`pg_config --includedir` -fPIC 

install: install-dev install-lib install-exe
	echo "make $(LIB) install"

install-dev: $(LIB)
	$(INSTALL_DATA) libfossology.a $(DESTDIR)$(LIBDIR)/libfossology.a
	$(INSTALL_DATA) libfossology.h $(DESTDIR)$(INCLUDEDIR)/libfossology.h

install-exe: $(EXE)
	for program in $(EXE); do \
	  $(INSTALL_PROGRAM) $$program $(DESTDIR)$(LIBEXECDIR)/$$program; \
	done

# If we ever do shared libs, they will go here
install-lib:

# uninstall only cleans up the files, not the directories that might have
# been created
uninstall: uninstall-dev uninstall-lib uninstall-exe
	echo "make $(LIB) uninstall"

uninstall-dev:
	rm -f $(DESTDIR)$(LIBDIR)/libfossology.a
	rm -f $(DESTDIR)$(INCLUDEDIR)/libfossology.h

uninstall-exe:
	for program in $(EXE); do \
	  rm -f $(DESTDIR)$(LIBEXECDIR)/$$program; \
	done

uninstall-lib:

test: all
	echo "make $(LIB) no tests "

clean:
	echo "make $(LIB) clean "
	rm -f $(LIB) $(EXE) *.o core

.PHONY: all clean test
.PHONY: install-dev install-lib install-exe
.PHONY: uninstall-dev uninstall-lib uninstall-exe