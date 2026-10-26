#!/usr/bin/env bash

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

# Parse DB_HOST for port or socket references and build EXTRA connection args
# This must be in global scope so recreate_db and create_db can access it
EXTRA=""
IFS=':' read -ra DB_HOST_PARTS <<< "$DB_HOST"
DB_HOSTNAME=${DB_HOST_PARTS[0]}
DB_SOCK_OR_PORT=${DB_HOST_PARTS[1]}

if ! [ -z "$DB_HOSTNAME" ] ; then
	if [ "$(echo "$DB_SOCK_OR_PORT" | grep -e '^[0-9]\{1,\}$')" ]; then
		EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
	elif ! [ -z "$DB_SOCK_OR_PORT" ] ; then
		EXTRA=" --socket=$DB_SOCK_OR_PORT"
	elif ! [ -z "$DB_HOSTNAME" ] ; then
		EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
	fi
fi

TMPDIR=${TMPDIR-/tmp}

path_error() {
	printf 'Unsafe test directory %s: %s\n' "$1" "$2" >&2
	exit 1
}

# Check ancestors before following a path or creating any directory below it.
check_directory() {
	local path="$1" kind="$2" create="$3" parent target writable
	[ -n "$path" ] || path_error "$path" 'path is empty'
	case "$path" in
		/*) ;;
		*) path="$PWD/$path" ;;
	esac
	while [ "$path" != '/' ] && [ "${path%/}" != "$path" ]; do
		path=${path%/}
	done
	if [ "$path" != '/' ]; then
		parent=$(dirname "$path")
		check_directory "$parent" ancestor "$create"
	fi

	if [ -L "$path" ]; then
		[ "$kind" = ancestor ] || path_error "$path" 'destination is a symlink'
		[ -n "$(find "$path" -prune -type l -user root -print)" ] || path_error "$path" 'ancestor symlink is not root-owned'
		target=$(readlink "$path") || path_error "$path" 'cannot read ancestor symlink'
		case "$target" in
			/*) ;;
			*) target="$parent/$target" ;;
		esac
		check_directory "$target" ancestor "$create"
		return
	fi

	if [ ! -e "$path" ]; then
		[ "$create" = yes ] || return 0
		( umask 077; mkdir -m 700 "$path" ) || path_error "$path" 'could not create directory safely'
	fi
	[ -d "$path" ] || path_error "$path" 'path is not a directory'
	if [ ! -O "$path" ]; then
		[ "$kind" = ancestor ] && [ -n "$(find "$path" -prune -user root -print)" ] || path_error "$path" 'directory is owned by another user'
	fi
	writable=$(find "$path" -prune \( -perm -0020 -o -perm -0002 \) -print) || path_error "$path" 'cannot check directory permissions'
	if [ -n "$writable" ]; then
		[ "$kind" = ancestor ] && [ -k "$path" ] || path_error "$path" 'directory is group or world writable'
	fi
}

if [ -z "${WP_TESTS_DIR+x}" ] || [ -z "${WP_CORE_DIR+x}" ]; then
	[ -n "${HOME:-}" ] || path_error 'HOME' 'HOME is required for the default test directories'
	DEFAULT_ROOT="$HOME/.mainwp-wp-tests"
	check_directory "$DEFAULT_ROOT" destination yes
fi
WP_TESTS_DIR=${WP_TESTS_DIR-"$DEFAULT_ROOT/wordpress-tests-lib"}
WP_CORE_DIR=${WP_CORE_DIR-"$DEFAULT_ROOT/wordpress"}
check_directory "$WP_CORE_DIR" destination no
check_directory "$WP_TESTS_DIR" destination no
check_directory "$TMPDIR" ancestor no

STAGING_DIR=$(mktemp -d "${TMPDIR%/}/mainwp-wp-tests.XXXXXX") || exit 1
trap 'rm -rf "$STAGING_DIR"' EXIT

download() {
    if [ "$(which curl)" ]; then
        curl -s "$1" > "$2";
    elif [ "$(which wget)" ]; then
        wget -nv -O "$2" "$1"
    fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"

elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		# version x.x.0 means the first release of the major version, so strip off the .0 and download version x.x
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	# http serves a single offer, whereas https serves multiple. we only want one
	download http://api.wordpress.org/core/version-check/1.7/ "$STAGING_DIR/wp-latest.json"
	LATEST_VERSION=$(grep -o '"version":"[^"]*' "$STAGING_DIR/wp-latest.json" | sed 's/"version":"//')
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi
set -ex

install_wp() {

	if [ -d "$WP_CORE_DIR" ]; then
		check_directory "$WP_CORE_DIR" destination no
		return;
	fi

	check_directory "$WP_CORE_DIR" destination yes

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		svn export --quiet https://core.svn.wordpress.org/trunk "$STAGING_DIR/wordpress"
		mv "$STAGING_DIR/wordpress/"* "$WP_CORE_DIR"
	else
		if [ "$WP_VERSION" == 'latest' ]; then
			local ARCHIVE_NAME='latest'
		elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
			# https serves multiple offers, whereas http serves single.
			download https://api.wordpress.org/core/version-check/1.7/ "$STAGING_DIR/wp-latest.json"
			if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
				# version x.x.0 means the first release of the major version, so strip off the .0 and download version x.x
				LATEST_VERSION=${WP_VERSION%??}
			else
				# otherwise, scan the releases and get the most up to date minor version of the major release
				local VERSION_ESCAPED=`echo "$WP_VERSION" | sed 's/\./\\\\./g'`
				LATEST_VERSION=$(grep -o '"version":"'"$VERSION_ESCAPED"'[^"]*' "$STAGING_DIR/wp-latest.json" | sed 's/"version":"//' | head -1)
			fi
			if [[ -z "$LATEST_VERSION" ]]; then
				local ARCHIVE_NAME="wordpress-$WP_VERSION"
			else
				local ARCHIVE_NAME="wordpress-$LATEST_VERSION"
			fi
		else
			local ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi
		download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "$STAGING_DIR/wordpress.tar.gz"
		tar --strip-components=1 -zxmf "$STAGING_DIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
	fi

}

install_test_suite() {
	local payload
	# portable in-place argument for both GNU sed and Mac OSX sed
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	# set up testing suite if it doesn't yet exist
	if [ -d "$WP_TESTS_DIR" ]; then
		check_directory "$WP_TESTS_DIR" destination no
	else
		# set up testing suite
		check_directory "$WP_TESTS_DIR" destination yes
	fi
	if [ ! -d "$WP_TESTS_DIR/includes" ] || [ ! -d "$WP_TESTS_DIR/data" ]; then
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$STAGING_DIR/includes"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$STAGING_DIR/data"
		for payload in includes data; do
			if [ ! -d "$WP_TESTS_DIR/$payload" ]; then
				mv "$STAGING_DIR/$payload" "$WP_TESTS_DIR/$payload"
			fi
		done
	fi

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$STAGING_DIR/wp-tests-config.php"
		# remove all forward slashes in the end
		WP_CORE_DIR=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
		sed "$ioption" "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$STAGING_DIR/wp-tests-config.php"
		sed "$ioption" "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$STAGING_DIR/wp-tests-config.php"
		sed "$ioption" "s/youremptytestdbnamehere/$DB_NAME/" "$STAGING_DIR/wp-tests-config.php"
		sed "$ioption" "s/yourusernamehere/$DB_USER/" "$STAGING_DIR/wp-tests-config.php"
		sed "$ioption" "s/yourpasswordhere/$DB_PASS/" "$STAGING_DIR/wp-tests-config.php"
		sed "$ioption" "s|localhost|${DB_HOST}|" "$STAGING_DIR/wp-tests-config.php"
		mv "$STAGING_DIR/wp-tests-config.php" "$WP_TESTS_DIR/wp-tests-config.php"
	fi

}

recreate_db() {
	shopt -s nocasematch
	if [[ $1 =~ ^(y|yes)$ ]]
	then
		mysqladmin drop $DB_NAME -f --user="$DB_USER" --password="$DB_PASS"$EXTRA
		create_db
		echo "Recreated the database ($DB_NAME)."
	else
		echo "Leaving the existing database ($DB_NAME) in place."
	fi
	shopt -u nocasematch
}

create_db() {
	mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS"$EXTRA
}

install_db() {

	if [ ${SKIP_DB_CREATE} = "true" ]; then
		return 0
	fi

	# EXTRA connection args are computed at script level (lines 15-30)

	# create database
	if [ "$(mysql --user="$DB_USER" --password="$DB_PASS"$EXTRA --execute='show databases;' | grep "^${DB_NAME}\$")" ]
	then
		echo "Reinstalling will delete the existing test database ($DB_NAME)"
		read -p 'Are you sure you want to proceed? [y/N]: ' DELETE_EXISTING_DB
		recreate_db $DELETE_EXISTING_DB
	else
		create_db
	fi
}

install_wp
install_test_suite
install_db
