import SaasLayout from '@/Layouts/SaasLayout';
import OwnerDashboard from '@/Components/Dashboard/Roles/OwnerDashboard';
import SupervisorDashboard from '@/Components/Dashboard/Roles/SupervisorDashboard';
import ConfirmationAgentDashboard from '@/Components/Dashboard/Roles/ConfirmationAgentDashboard';
import FulfillmentAgentDashboard from '@/Components/Dashboard/Roles/FulfillmentAgentDashboard';
import DeliveryAgentDashboard from '@/Components/Dashboard/Roles/DeliveryAgentDashboard';
import InventoryDashboard from '@/Components/Dashboard/Roles/InventoryDashboard';

const DASHBOARDS = {
    owner: OwnerDashboard,
    supervisor: SupervisorDashboard,
    confirmation: ConfirmationAgentDashboard,
    fulfillment: FulfillmentAgentDashboard,
    delivery: DeliveryAgentDashboard,
    inventory: InventoryDashboard,
};

/**
 * /dashboard renders a different dashboard per role — see
 * DashboardController::resolveDashboardKind(). This page is deliberately a
 * thin router: the actual content lives in Components/Dashboard/Roles/*,
 * each built from the same shared components/tokens as the rest of the app.
 */
export default function Index({ dashboard_kind = 'owner', ...props }) {
    const Dashboard = DASHBOARDS[dashboard_kind] ?? OwnerDashboard;

    return (
        <SaasLayout>
            <Dashboard {...props} />
        </SaasLayout>
    );
}
