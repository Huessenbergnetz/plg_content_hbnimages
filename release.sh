#!/bin/bash
INFOFILE="hbnimages.xml"
PKGNAME=plg_content_hbnimages

# get current version
VERSION=`grep "<version>" $INFOFILE | sed 's|.*<version>\([0-9\.]*\)</version>.*|\1|'`

TARBALL="${PKGNAME}-${VERSION}.tar.gz"

# remove previous package
if [ -f $TARBALL ]; then
    rm $TARBALL
fi

tar -c -z -f $TARBALL --exclude-vcs \
    forms \
    language \
    services \
    src \
    hbnimages.xml
