import type { SVGAttributes } from 'react';

/**
 * Omen mark: a ring (the "O") holding three grid lines, like a grid bot's price levels.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M20 2a18 18 0 1 1 0 36a18 18 0 0 1 0-36Zm0 4.5a13.5 13.5 0 1 0 0 27a13.5 13.5 0 0 0 0-27Z"
            />
            <rect x="11" y="12.5" width="18" height="3" rx="1.5" />
            <rect x="9" y="18.5" width="22" height="3" rx="1.5" />
            <rect x="11" y="24.5" width="18" height="3" rx="1.5" />
        </svg>
    );
}
