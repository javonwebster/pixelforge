import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { adminNavItems, mainNavItems } from '@/lib/navigation';

/** The main nav, plus the admin report link for admins. */
export function useNavItems() {
    const page = usePage();

    return computed(() =>
        page.props.auth.user?.is_admin
            ? [...mainNavItems, ...adminNavItems]
            : mainNavItems,
    );
}
