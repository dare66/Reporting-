import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import { AuthProvider, useAuth } from "./hooks/useAuth";
import { useTheme } from "./hooks/useTheme";
import { AppLayout } from "./layouts/AppLayout";
import { Spinner } from "./components/ui";
import { Login } from "./pages/Login";
import { Dashboard } from "./pages/Dashboard";
import { Assistant } from "./pages/Assistant";
import { Diagnostics } from "./pages/Diagnostics";
import { Discovery } from "./pages/Discovery";
import { Inventory } from "./pages/Inventory";
import { HistoryPage } from "./pages/History";
import { Reports } from "./pages/Reports";
import { AuditPage } from "./pages/Audit";
import { SettingsPage } from "./pages/Settings";

function Shell() {
  useTheme();
  const { loading, user, can } = useAuth();
  if (loading) return <div className="flex h-full items-center justify-center"><Spinner label="Connecting to laptop…" /></div>;
  if (!user) return <Login />;
  return (
    <AppLayout>
      <Routes>
        <Route path="/" element={<Dashboard />} />
        <Route path="/assistant" element={<Assistant />} />
        <Route path="/diagnostics" element={<Diagnostics />} />
        <Route path="/discovery" element={<Discovery />} />
        <Route path="/discovery/:reference" element={<Discovery />} />
        <Route path="/inventory" element={<Inventory />} />
        <Route path="/history" element={<HistoryPage />} />
        <Route path="/reports" element={<Reports />} />
        {can("admin") && <Route path="/audit" element={<AuditPage />} />}
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </AppLayout>
  );
}

export default function App() {
  return <BrowserRouter><AuthProvider><Shell /></AuthProvider></BrowserRouter>;
}
