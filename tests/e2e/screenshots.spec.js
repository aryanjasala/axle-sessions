// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp, logIn } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const { UA, createPeople, removePeople, addSessions, clearSessions, makeNotMe } = require( './seed' );

test.describe( 'Screenshots', () => {
	let context;
	let page;

	test.beforeAll( async ( { browser } ) => {
		prepareSite( 'Example Shop' );
		wp( 'option', 'delete', 'axle_sessions_settings' );
		createPeople();
		clearSessions();

		// The admin signs in from a Mac, so "This device" reads well.
		context = await browser.newContext( { storageState: { cookies: [], origins: [] }, userAgent: UA.mac, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
		await logIn( page, 'admin', 'admin' );
		await page.waitForURL( /wp-admin/ );

		addSessions( [
			[ 'admin', 'iphone', '203.0.113.24', 26 * 60, 40 ],
			[ 'admin', 'ipad', '198.51.100.71', 9 * 60, 7 * 60 ],
			[ 'maria', 'windows', '192.0.2.18', 180, 4 ],
			[ 'maria', 'android', '203.0.113.90', 30 * 60, 95 ],
			[ 'james', 'edge', '198.51.100.12', 50, 12 ],
			[ 'priya', 'iphone', '192.0.2.200', 20 * 60, 3 * 60 ],
		] );
	} );

	test.afterAll( async () => {
		await context.close();
		wp( 'option', 'delete', 'axle_sessions_settings' );
		removePeople();
	} );

	test( '1: Profile > Your sessions', async () => {
		await page.goto( '/wp-admin/profile.php' );
		const section = page.locator( '.usm-profile' );
		await expect( section.locator( '.usm-session' ) ).toHaveCount( 3 );
		await section.scrollIntoViewIfNeeded();
		const box = await section.boundingBox();
		await shot( page, 1, { clip: { x: 150, y: box.y - 16, width: 1130, height: box.height + 32 } } );
	} );

	test( '2: Users > Sessions with every signed-in device', async () => {
		await page.goto( '/wp-admin/users.php?page=user-sessions' );
		await expect( page.locator( 'tbody .usm-session' ) ).toHaveCount( 7 );
		await shot( page, 2 );
	} );

	test( '3: Users > Sessions > Policies with policies per role', async () => {
		wp( 'axle-sessions', 'set-policy', 'administrator', '--max-sessions=3', '--idle-minutes=60', '--lifetime-hours=12' );
		wp( 'axle-sessions', 'set-policy', 'editor', '--max-sessions=2', '--idle-minutes=120' );
		wp( 'axle-sessions', 'set-policy', 'subscriber', '--max-sessions=1', '--remember-hours=720' );
		wp( 'axle-sessions', 'settings', '--new-device-email=on' );
		await page.goto( '/wp-admin/users.php?page=user-sessions&tab=policies' );
		await expect( page.locator( '#usm-administrator-idle_minutes' ) ).toHaveValue( '60' );
		await shot( page, 3, { fullPage: true } );
	} );

	test( '4: a login refused over the limit', async ( { browser } ) => {
		wp( 'axle-sessions', 'set-policy', 'editor', '--max-sessions=2' );
		wp( 'axle-sessions', 'settings', '--limit-mode=block' );
		const other = await browser.newContext( { storageState: { cookies: [], origins: [] }, userAgent: UA.ipad, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		const login = await other.newPage();
		await logIn( login, 'maria', 'demo-password-123' );
		await expect( login.locator( '.usm-swap-device' ).first() ).toBeVisible();
		await expect( login.locator( '#login' ) ).toContainText( 'This account can be signed in on 2 devices at a time' );
		await shot( login, 4, { clip: { x: 390, y: 0, width: 500, height: 640 } } );
		await other.close();
	} );

	test( '5: the This wasn\'t me page, with the application passwords it revokes', async ( { browser } ) => {
		wp( 'user', 'application-password', 'create', 'maria', 'Zapier', '--porcelain' );
		wp( 'user', 'application-password', 'create', 'maria', 'Deploy script', '--porcelain' );
		const link = makeNotMe( 'maria' );
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] }, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		const tab = await visitor.newPage();
		await tab.goto( link );
		await expect( tab.locator( '.axle-guest-box' ) ).toContainText( 'Zapier' );
		await shot( tab, 5, { clip: { x: 390, y: 0, width: 500, height: 640 } } );
		await visitor.close();
	} );

	test( '6: Sign everyone out, with the application passwords to revoke', async () => {
		wp( 'user', 'application-password', 'create', 'priya', 'Backup job', '--porcelain' );
		await page.goto( '/wp-admin/users.php?page=user-sessions&usm_incident=1' );
		await expect( page.locator( '.usm-app-preview' ) ).toContainText( 'This revokes 3 application passwords of 2 users.' );
		await shot( page, 6, { fullPage: true } );
		for ( const login of [ 'maria', 'priya' ] ) {
			for ( const uuid of wp( 'user', 'application-password', 'list', login, '--field=uuid' ).split( '\n' ).filter( Boolean ) ) {
				wp( 'user', 'application-password', 'delete', login, uuid );
			}
		}
	} );
} );
