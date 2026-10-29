import {useState} from 'react';
import {fetchOne} from '@moodle/lms/core/ajax';

/**
 * Dismissible notice explaining the migrated Sharing Cart basket icon setting.
 *
 * @param {Object} props
 * @param {string} props.message
 * @param {string} props.dismissLabel
 * @param {string} props.dismissError
 * @returns {JSX.Element|null}
 */
export default function BasketIconsNotice({message, dismissLabel, dismissError}) {
    const [dismissed, setDismissed] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    /**
     * Save this user's dismissal before removing the notice.
     */
    const dismissNotice = () => {
        if (saving) {
            return;
        }

        setSaving(true);
        fetchOne({
            methodname: 'core_user_update_user_preferences',
            args: {
                preferences: [{
                    type: 'block_sharing_cart_basket_notice_dismissed',
                    value: '1',
                }],
            },
        }).then(() => {
            setDismissed(true);
        }).catch(() => {
            setError(dismissError);
            setSaving(false);
        });
    };

    if (dismissed) {
        return null;
    }

    return (
        <div className="alert alert-info alert-dismissible mb-3 sharing-cart-basket-notice" role="status">
            <span>{message}</span>
            <button
                type="button"
                className="btn-close"
                aria-label={dismissLabel}
                disabled={saving}
                onClick={dismissNotice}
            />
            {error && <div className="text-danger mt-2" role="alert">{error}</div>}
        </div>
    );
}
