/**
 * Entry of the update follow-up script, loaded by
 * AdminAssets::enqueue_update_follow_up() on the WordPress screens that
 * update plugins through `updates.js` (see update-follow-up/followUp.ts).
 */
import {
	bindUpdateFollowUp,
	type FollowUpWindow,
} from './update-follow-up/followUp';

bindUpdateFollowUp( window as unknown as FollowUpWindow );
