/**
 * Entry of the site check baseline script, loaded by
 * AdminAssets::enqueue_site_check_baseline() on the Plugins and Updates
 * screens when site checks are enabled (see site-check/baseline.ts).
 */
import { bindBaseline, type BaselineWindow } from './site-check/baseline';

bindBaseline( window as unknown as BaselineWindow );
