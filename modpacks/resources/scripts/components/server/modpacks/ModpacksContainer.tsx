import React, { useState, useCallback, useRef } from 'react';
import http from '@/api/http';
import useFlash from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import Button from '@/components/elements/Button';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Spinner from '@/components/elements/Spinner';
import { useStoreState } from 'easy-peasy';

// ─── API helpers ──────────────────────────────────────────────────────────────

const searchModpacks = (serverUuid, provider, query, mcVersion, loader) =>
    http.get(`/api/client/servers/${serverUuid}/modpacks/search`, {
        params: { provider, query, mc_version: mcVersion || undefined, loader: loader || undefined },
    });

const getVersions = (serverUuid, provider, projectId) =>
    http.get(`/api/client/servers/${serverUuid}/modpacks/${provider}/${projectId}/versions`);

const installModpack = (serverUuid, provider, projectId, versionId, wipeServer) =>
    http.post(`/api/client/servers/${serverUuid}/modpacks/install`, {
        provider, project_id: projectId, version_id: versionId, wipe_server: wipeServer,
    });

const getStatus = (serverUuid) =>
    http.get(`/api/client/servers/${serverUuid}/modpacks/status`);

// ─── Styled components ────────────────────────────────────────────────────────

const Grid = styled.div`${tw`grid gap-4`}`;

const Card = styled.div`
    ${tw`bg-neutral-700 rounded-lg p-4 border border-neutral-600`}
    transition: border-color 0.15s;
    &:hover { border-color: #4f46e5; }
`;

const ModpackCard = styled(Card)`
    ${tw`flex items-start gap-4 cursor-pointer`}
    ${({ selected }) => selected && tw`border-indigo-500 bg-neutral-600`}
`;

const ModpackIcon = styled.img`
    ${tw`w-16 h-16 rounded-md object-cover flex-shrink-0`}
`;

const ModpackIconFallback = styled.div`
    ${tw`w-16 h-16 rounded-md bg-neutral-600 flex-shrink-0 flex items-center justify-center text-2xl`}
`;

const Badge = styled.span`
    ${tw`text-xs px-2 py-0.5 rounded-full`}
    background: rgba(79, 70, 229, 0.2);
    color: #818cf8;
    border: 1px solid rgba(79, 70, 229, 0.3);
`;

const StatusBar = styled.div`
    ${tw`rounded-lg p-4 flex items-center gap-3`}
    ${({ status }) => {
        if (status === 'installing') return tw`bg-yellow-900 bg-opacity-40 border border-yellow-600`;
        if (status === 'success') return tw`bg-green-900 bg-opacity-40 border border-green-600`;
        if (status === 'failed') return tw`bg-red-900 bg-opacity-40 border border-red-600`;
        return tw`bg-neutral-700 border border-neutral-600`;
    }}
`;

const VersionBadge = styled.button`
    ${tw`text-sm px-3 py-1.5 rounded-md border transition-all`}
    ${({ selected }) => selected
        ? tw`bg-indigo-600 border-indigo-500 text-white`
        : tw`bg-neutral-700 border-neutral-600 text-neutral-300 hover:border-indigo-500`}
`;

const WarnBox = styled.div`
    ${tw`bg-opacity-30 border rounded-lg p-3 text-sm`}
`;

// ─── Subcomponents ────────────────────────────────────────────────────────────

const SearchFilters = ({ provider, setProvider, mcVersion, setMcVersion, loader, setLoader, query, setQuery, onSearch, loading }) => (
    <div css={tw`grid grid-cols-1 md:grid-cols-5 gap-3`}>
        <Select value={provider} onChange={e => setProvider(e.target.value)}>
            <option value="modrinth">Modrinth</option>
            <option value="curseforge">CurseForge</option>
            <option value="ftb">Feed The Beast</option>
        </Select>
        <Input
            placeholder="Buscar modpack..."
            value={query}
            onChange={e => setQuery(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && onSearch()}
            css={tw`md:col-span-2`}
        />
        <Input
            placeholder="MC Version (ej. 1.20.1)"
            value={mcVersion}
            onChange={e => setMcVersion(e.target.value)}
        />
        <Select value={loader} onChange={e => setLoader(e.target.value)}>
            <option value="">Cualquier loader</option>
            <option value="forge">Forge</option>
            <option value="fabric">Fabric</option>
            <option value="neoforge">NeoForge</option>
            <option value="quilt">Quilt</option>
        </Select>
        <Button onClick={onSearch} disabled={loading || query.length < 2} css={tw`md:col-span-5`}>
            {loading ? <Spinner size="small" /> : 'Buscar'}
        </Button>
    </div>
);

const ModpackResult = ({ modpack, selected, onClick }) => (
    <ModpackCard selected={selected} onClick={() => onClick(modpack)}>
        {modpack.icon_url
            ? <ModpackIcon src={modpack.icon_url} alt={modpack.name} />
            : <ModpackIconFallback>📦</ModpackIconFallback>
        }
        <div css={tw`flex-1 min-w-0`}>
            <div css={tw`flex items-center gap-2 flex-wrap`}>
                <span css={tw`font-semibold text-neutral-200`}>{modpack.name}</span>
                {modpack.loaders?.map(l => <Badge key={l}>{l}</Badge>)}
            </div>
            <p css={tw`text-sm text-neutral-400 mt-1 line-clamp-2`}>{modpack.description}</p>
            <div css={tw`flex items-center gap-4 mt-2 text-xs text-neutral-500`}>
                <span>⬇ {(modpack.downloads || 0).toLocaleString()} descargas</span>
                {modpack.mc_versions?.length > 0 && (
                    <span>MC: {modpack.mc_versions.slice(0, 3).join(', ')}{modpack.mc_versions.length > 3 ? '...' : ''}</span>
                )}
            </div>
        </div>
        {selected && <span css={tw`text-indigo-400 text-lg flex-shrink-0`}>✓</span>}
    </ModpackCard>
);

const VersionSelector = ({ versions, selectedVersion, onSelect, loading }) => {
    if (loading) return <div css={tw`flex justify-center p-4`}><Spinner /></div>;

    if (!versions.length) return (
        <p css={tw`text-neutral-400 text-sm text-center py-4`}>No se encontraron versiones con server pack.</p>
    );

    return (
        <div>
            <p css={tw`text-sm text-neutral-400 mb-3`}>
                Selecciona una versión ({versions.length} disponibles):
            </p>
            <div css={tw`flex flex-wrap gap-2 max-h-48 overflow-y-auto`}>
                {versions.map(v => (
                    <VersionBadge
                        key={v.id}
                        selected={selectedVersion?.id === v.id}
                        onClick={() => onSelect(v)}
                        title={v.mc_versions?.join(', ')}
                    >
                        {v.version || v.name}
                        {v.has_server_pack && <span css={tw`ml-1 text-green-400`} title="Incluye server pack">●</span>}
                    </VersionBadge>
                ))}
            </div>
        </div>
    );
};

// ─── Main Component ───────────────────────────────────────────────────────────

export default function ModpacksContainer() {
    const uuid = ServerContext.useStoreState(s => s.server.data?.uuid);
    const { addError, clearFlashes } = useFlash();

    // Search state
    const [provider, setProvider] = useState('modrinth');
    const [query, setQuery] = useState('');
    const [mcVersion, setMcVersion] = useState('');
    const [loader, setLoader] = useState('');
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);

    // Selection state
    const [selected, setSelected] = useState(null);
    const [versions, setVersions] = useState([]);
    const [loadingVersions, setLoadingVersions] = useState(false);
    const [selectedVersion, setSelectedVersion] = useState(null);

    // Install state
    const [wipeServer, setWipeServer] = useState(true);
    const [installing, setInstalling] = useState(false);
    const [installStatus, setInstallStatus] = useState(null); // null | 'installing' | 'success' | 'failed'
    const statusPollRef = useRef(null);

    const handleSearch = useCallback(async () => {
        if (!query || query.length < 2) return;
        clearFlashes('modpacks');
        setSearching(true);
        setResults([]);
        setSelected(null);
        setVersions([]);
        setSelectedVersion(null);

        try {
            const { data } = await searchModpacks(uuid, provider, query, mcVersion, loader);
            setResults(data.data || []);
        } catch (err) {
            addError({ key: 'modpacks', message: err.message || 'Error buscando modpacks.' });
        } finally {
            setSearching(false);
        }
    }, [uuid, provider, query, mcVersion, loader]);

    const handleSelectModpack = useCallback(async (modpack) => {
        setSelected(modpack);
        setSelectedVersion(null);
        setVersions([]);
        setLoadingVersions(true);

        try {
            const { data } = await getVersions(uuid, provider, modpack.id);
            setVersions(data.data || []);
        } catch (err) {
            addError({ key: 'modpacks', message: 'Error obteniendo versiones.' });
        } finally {
            setLoadingVersions(false);
        }
    }, [uuid, provider]);

    const pollInstallStatus = useCallback(() => {
        statusPollRef.current = setInterval(async () => {
            try {
                const { data } = await getStatus(uuid);
                if (!data.data?.installing) {
                    clearInterval(statusPollRef.current);
                    setInstalling(false);
                    setInstallStatus('success');
                }
            } catch {
                clearInterval(statusPollRef.current);
                setInstalling(false);
                setInstallStatus('failed');
            }
        }, 3000);
    }, [uuid]);

    const handleInstall = useCallback(async () => {
        if (!selected || !selectedVersion) return;
        clearFlashes('modpacks');
        setInstalling(true);
        setInstallStatus('installing');

        try {
            await installModpack(uuid, provider, selected.id, selectedVersion.id, wipeServer);
            pollInstallStatus();
        } catch (err) {
            setInstalling(false);
            setInstallStatus('failed');
            addError({ key: 'modpacks', message: err.message || 'Error iniciando instalación.' });
        }
    }, [uuid, provider, selected, selectedVersion, wipeServer]);

    return (
        <ServerContentBlock title="Modpacks">
            <Grid>

                {/* Status bar */}
                {installStatus && (
                    <StatusBar status={installStatus}>
                        {installStatus === 'installing' && <Spinner size="small" />}
                        <span css={tw`text-sm`}>
                            {installStatus === 'installing' && 'Instalando modpack... Wings está ejecutando el script.'}
                            {installStatus === 'success' && '✓ Instalación completada. El servidor está listo.'}
                            {installStatus === 'failed' && '✗ La instalación falló. Revisa los logs del servidor.'}
                        </span>
                        {installStatus !== 'installing' && (
                            <button
                                css={tw`ml-auto text-neutral-400 hover:text-white text-sm`}
                                onClick={() => setInstallStatus(null)}
                            >
                                ✕
                            </button>
                        )}
                    </StatusBar>
                )}

                {/* Search */}
                <Card>
                    <h3 css={tw`text-neutral-200 font-semibold mb-4`}>Buscar Modpack</h3>
                    <SearchFilters
                        provider={provider} setProvider={setProvider}
                        mcVersion={mcVersion} setMcVersion={setMcVersion}
                        loader={loader} setLoader={setLoader}
                        query={query} setQuery={setQuery}
                        onSearch={handleSearch} loading={searching}
                    />
                </Card>

                {/* Results */}
                {results.length > 0 && (
                    <Card>
                        <h3 css={tw`text-neutral-200 font-semibold mb-4`}>
                            Resultados ({results.length})
                        </h3>
                        <div css={tw`grid gap-2 max-h-96 overflow-y-auto pr-1`}>
                            {results.map(r => (
                                <ModpackResult
                                    key={r.id}
                                    modpack={r}
                                    selected={selected?.id === r.id}
                                    onClick={handleSelectModpack}
                                />
                            ))}
                        </div>
                    </Card>
                )}

                {/* Version selector */}
                {selected && (
                    <Card>
                        <h3 css={tw`text-neutral-200 font-semibold mb-1`}>{selected.name}</h3>
                        <p css={tw`text-sm text-neutral-400 mb-4`}>Selecciona la versión a instalar</p>
                        <VersionSelector
                            versions={versions}
                            selectedVersion={selectedVersion}
                            onSelect={setSelectedVersion}
                            loading={loadingVersions}
                        />
                    </Card>
                )}

                {/* Install panel */}
                {selectedVersion && (
                    <Card>
                        <h3 css={tw`text-neutral-200 font-semibold mb-4`}>Confirmar instalación</h3>

                        <div css={tw`grid grid-cols-2 gap-3 mb-4 text-sm`}>
                            <div>
                                <span css={tw`text-neutral-500`}>Modpack</span>
                                <p css={tw`text-neutral-200`}>{selected.name}</p>
                            </div>
                            <div>
                                <span css={tw`text-neutral-500`}>Versión</span>
                                <p css={tw`text-neutral-200`}>{selectedVersion.version}</p>
                            </div>
                            <div>
                                <span css={tw`text-neutral-500`}>Minecraft</span>
                                <p css={tw`text-neutral-200`}>{selectedVersion.mc_versions?.join(', ') || '—'}</p>
                            </div>
                            <div>
                                <span css={tw`text-neutral-500`}>Loader</span>
                                <p css={tw`text-neutral-200`}>{selectedVersion.loaders?.join(', ') || 'auto'}</p>
                            </div>
                        </div>

                        <label css={tw`flex items-center gap-3 cursor-pointer mb-4`}>
                            <input
                                type="checkbox"
                                checked={wipeServer}
                                onChange={e => setWipeServer(e.target.checked)}
                                css={tw`w-4 h-4`}
                            />
                            <span css={tw`text-sm text-neutral-300`}>
                                Limpiar servidor antes de instalar (recomendado)
                            </span>
                        </label>

                        {wipeServer && (
                            <WarnBox css={tw`mb-4`}>
                                ⚠ Esto eliminará todos los archivos del servidor. Los mundos y configuraciones se perderán.
                            </WarnBox>
                        )}

                        <Button
                            color="red"
                            onClick={handleInstall}
                            disabled={installing}
                            css={tw`w-full`}
                        >
                            {installing
                                ? <><Spinner size="small" css={tw`mr-2`} /> Instalando...</>
                                : 'Instalar Modpack'
                            }
                        </Button>
                    </Card>
                )}

                {results.length === 0 && !searching && (
                    <p css={tw`text-neutral-500 text-sm text-center py-8`}>
                        Busca un modpack arriba para comenzar.
                    </p>
                )}

            </Grid>
        </ServerContentBlock>
    );
}
