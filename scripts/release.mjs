/**
 * Package UpdateLens into an installable plugin ZIP.
 *
 * Run via `npm run release` (which builds the admin app first).
 * Output: release/updatelens/ (staging copy) and release/updatelens-<version>.zip
 *
 * Production PHP dependencies are installed into the staging copy with
 * `composer install --no-dev`, so dev tooling (PHPCS etc.) is never shipped and
 * the working-copy vendor/ is left untouched. Set COMPOSER_BIN to override the
 * Composer command (default: "composer").
 */
import { execSync } from 'node:child_process';
import {
	cpSync,
	createWriteStream,
	existsSync,
	mkdirSync,
	readFileSync,
	rmSync,
} from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ZipArchive } from 'archiver';

const SLUG = 'updatelens';
const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const RELEASE_DIR = join( ROOT, 'release' );
const STAGE_DIR = join( RELEASE_DIR, SLUG );

/**
 * Everything shipped in the ZIP. Anything not listed is left out.
 * src/ and the build config are included so the unminified source of the
 * admin app ships with the plugin (WordPress.org guideline 4).
 */
const INCLUDE = [
	'updatelens.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'composer.json',
	'includes',
	'libs',
	'languages',
	'assets/admin/dist',
	'src',
	'package.json',
	'package-lock.json',
	'vite.config.ts',
	'tsconfig.json',
	'tailwind.config.js',
	'postcss.config.cjs',
];

const isExcluded = ( path ) =>
	path.endsWith( '.map' ) ||
	basename( path ) === '.gitkeep' ||
	// Present only while `npm run dev` is running; it would point the site at localhost.
	basename( path ) === 'vite-dev-server.json';

function fail( message ) {
	console.error( `release: ${ message }` );
	process.exit( 1 );
}

function assertVersionsMatch() {
	const version = JSON.parse(
		readFileSync( join( ROOT, 'package.json' ), 'utf8' )
	).version;
	const main = readFileSync( join( ROOT, 'updatelens.php' ), 'utf8' );
	const readme = readFileSync( join( ROOT, 'readme.txt' ), 'utf8' );

	const found = {
		'package.json': version,
		'updatelens.php header': main.match(
			/^\s*\*\s*Version:\s*(\S+)/m
		)?.[ 1 ],
		UPDATELENS_VERSION: main.match(
			/define\(\s*'UPDATELENS_VERSION',\s*'([^']+)'/
		)?.[ 1 ],
		'readme.txt Stable tag': readme.match( /^Stable tag:\s*(\S+)/m )?.[ 1 ],
	};

	const mismatched = Object.entries( found ).filter(
		( [ , value ] ) => value !== version
	);
	if ( mismatched.length ) {
		fail(
			`version mismatch (expected ${ version }): ${ mismatched
				.map( ( [ where, value ] ) => `${ where }=${ value }` )
				.join( ', ' ) }`
		);
	}
	return version;
}

async function zip( sourceDir, destFile ) {
	const output = createWriteStream( destFile );
	const archive = new ZipArchive( { zlib: { level: 9 } } );
	const done = new Promise( ( resolve, reject ) => {
		output.on( 'close', resolve );
		archive.on( 'error', reject );
	} );
	archive.pipe( output );
	archive.directory( sourceDir, SLUG );
	await archive.finalize();
	await done;
}

const version = assertVersionsMatch();

if ( ! existsSync( join( ROOT, 'assets/admin/dist/manifest.json' ) ) ) {
	fail( 'assets/admin/dist/manifest.json not found. Run `npm run build`.' );
}

rmSync( RELEASE_DIR, { recursive: true, force: true } );
mkdirSync( STAGE_DIR, { recursive: true } );

for ( const entry of INCLUDE ) {
	const from = join( ROOT, entry );
	if ( ! existsSync( from ) ) {
		continue;
	}
	cpSync( from, join( STAGE_DIR, entry ), {
		recursive: true,
		filter: ( src ) => ! isExcluded( src ),
	} );
}

const composer = process.env.COMPOSER_BIN || 'composer';
execSync(
	`${ composer } install --no-dev --optimize-autoloader --no-interaction --no-progress`,
	{ cwd: STAGE_DIR, stdio: 'inherit' }
);
rmSync( join( STAGE_DIR, 'composer.lock' ), { force: true } );

const zipFile = join( RELEASE_DIR, `${ SLUG }-${ version }.zip` );
await zip( STAGE_DIR, zipFile );
console.log( `release: wrote ${ zipFile }` );
