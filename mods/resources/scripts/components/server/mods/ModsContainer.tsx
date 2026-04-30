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

const searchMods = (serverUuid, provider, query, mcVersion, loader) =>
    http.get(`/api/client/servers/${serverUuid}/mods/search`, {
        params: { provider, query, mc_version: mcVersion || undefined, loader: loader || undefined },
    });

const getVersions = (serverUuid, provider, projectId) =>
    http.get(`/api/client/servers/${serverUuid}/mods/${provider}/${projectId}/versions`);

const installMod = (serverUuid, provider, projectId, versionId, filename, downloadUrl, oldFilename) =>
    http.post(`/api/client/servers/${serverUuid}/mods/install`, {
        provider, project_id: projectId, version_id: versionId, filename, download_url: downloadUrl, old_filename: oldFilename,
    });

const getInstalled = (serverUuid) =>
    http.get(`/api/client/servers/${serverUuid}/mods/installed`);

const getStatus = (serverUuid) =>
    http.get(`/api/client/servers/${serverUuid}/mods/status`);

const deleteMod = (serverUuid, filename) =>
    http.delete(`/api/client/servers/${serverUuid}/mods/${encodeURIComponent(filename)}`);

const Grid = styled.div`${tw`grid gap-4`}`;

const Card = styled.div`
    ${tw`bg-neutral-700 rounded-lg p-4 border border-neutral-600`}
    transition: border-color 0.15s;
    &:hover { border-color: #4f46e5; }
`;

const ModCard = styled(Card)`
    ${tw`flex items-start gap-4 cursor-pointer`}
    ${({ selected }) => selected && tw`border-indigo-500 bg-neutral-600`}
`;

const ModIcon = styled.img`
    ${tw`w-16 h-16 rounded-md object-cover flex-shrink-0`}
`;

const ModIconFallback = styled.div`
    ${tw`w-16 h-16 rounded-md bg-neutral-600 flex-shrink-0 flex items-center justify-center text-2xl`}
`;

const Badge = styled.span`
    ${tw`text-xs px-2 py-0.5 rounded-full`}
    background: rgba(79, 70, 229, 0.2);
    color: #818cf8;
    border: 1px solid rgba(79, 70, 229, 0.3);
`;

const InstalledCheck = styled.span`
    ${tw`ml-2 px-2 py-0.5 rounded-full text-xs font-semibold`}
    background: rgba(34, 197, 94, 0.2);
    color: #4ade80;
    border: 1px solid rgba(34, 197, 94, 0.4);
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

const VersionButton = styled.button`
    ${tw`text-sm px-3 py-1.5 rounded-md border transition-all flex items-center gap-2`}
    ${({ selected }) => selected
        ? tw`bg-indigo-600 border-indigo-500 text-white`
        : tw`bg-neutral-700 border-neutral-600 text-neutral-300 hover:border-indigo-500`}
    ${({ installed, selected }) => installed && !selected && tw`border-green-600 bg-green-900 bg-opacity-20`}
`;

const DeleteButton = styled.button`
    ${tw`text-xs px-2 py-1 rounded border transition-colors`}
    background: rgba(239, 68, 68, 0.15);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.4);
    &:hover { background: rgba(239, 68, 68, 0.3); color: #fca5a5; }
`;

const SearchFilters = ({ provider, setProvider, mcVersion, setMcVersion, loader, setLoader, query, setQuery, onSearch, loading }) => (
    <div css={tw`grid grid-cols-1 md:grid-cols-5 gap-3`}>
        <Select value={provider} onChange={e => setProvider(e.target.value)}>
            <option value="curseforge">CurseForge</option>
        </Select>
        <Input
            placeholder="Buscar mod..."
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

const ModResult = ({ mod, selected, onClick }) => (
    <ModCard selected={selected} onClick={() => onClick(mod)}>
        {mod.icon_url
            ? <ModIcon src={mod.icon_url} alt={mod.name} />
            : <ModIconFallback>🧩</ModIconFallback>
        }
        <div css={tw`flex-1 min-w-0`}>
            <div css={tw`flex items-center gap-2 flex-wrap`}>
                <span css={tw`font-semibold text-neutral-200`}>{mod.name}</span>
                {mod.loaders?.slice(0, 2).map(l => <Badge key={l}>{l}</Badge>)}
            </div>
            <p css={tw`text-sm text-neutral-400 mt-1 line-clamp-2`}>{mod.description}</p>
            <div css={tw`flex items-center gap-4 mt-2 text-xs text-neutral-500`}>
                <span>⬇ {(mod.downloads || 0).toLocaleString()}</span>
                {mod.mc_versions?.length > 0 && (
                    <span>MC: {mod.mc_versions.slice(0, 3).join(', ')}{mod.mc_versions.length > 3 ? '...' : ''}</span>
                )}
            </div>
        </div>
        {mod.installed && <InstalledCheck>✓ Instalado</InstalledCheck>}
        {selected && !mod.installed && <span css={tw`text-indigo-400 text-lg flex-shrink-0`}>✓</span>}
    </ModCard>
);

const VersionSelector = ({ versions, selectedVersion, installedIds, onSelect, onRemove, loading }) => {
    if (loading) return <div css={tw`flex justify-center p-4`}><Spinner /></div>;

    if (!versions.length) return (
        <p css={tw`text-neutral-400 text-sm text-center py-4`}>No se encontraron versiones.</p>
    );

    return (
        <div>
            <p css={tw`text-sm text-neutral-400 mb-3`}>
                Selecciona una versión ({versions.length} disponibles):
            </p>
            <div css={tw`grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto`}>
                {versions.map(v => {
                    const isInstalled = v.filename ? installedIds.includes(v.filename) : false;
                    return (
                        <div key={v.id} css={tw`flex items-center gap-2`}>
                            <VersionButton
                                selected={selectedVersion?.id === v.id}
                                installed={isInstalled}
                                onClick={() => onSelect(v)}
                                title={v.mc_versions?.join(', ')}
                                css={tw`flex-1 justify-start`}
                            >
                                {v.version || v.name}
                                {isInstalled && <span css={tw`w-2 h-2 rounded-full bg-green-400`} title="Ya instalado" />}
                            </VersionButton>
                            {isInstalled && (
                                <DeleteButton onClick={() => onRemove(v)} title="Eliminar">
                                    ✕
                                </DeleteButton>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
};

export default function ModsContainer() {
    const uuid = ServerContext.useStoreState(s => s.server.data?.uuid);
    const { addError, clearFlashes } = useFlash();

    const [provider, setProvider] = useState('modrinth');
    const [query, setQuery] = useState('');
    const [mcVersion, setMcVersion] = useState('');
    const [loader, setLoader] = useState('');
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);

    const [selected, setSelected] = useState(null);
    const [versions, setVersions] = useState([]);
    const [loadingVersions, setLoadingVersions] = useState(false);
    const [selectedVersion, setSelectedVersion] = useState(null);

    const [installedFiles, setInstalledFiles] = useState(new Set());
    const [installedModFile, setInstalledModFile] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const [installing, setInstalling] = useState(false);
    const [installStatus, setInstallStatus] = useState(null);
    const statusPollRef = useRef(null);

    const loadInstalledMods = useCallback(async () => {
        try {
            const { data } = await getInstalled(uuid);
            const filenames = new Set((data.data || []).map(m => m.name));
            setInstalledFiles(filenames);
        } catch {
            // Silently fail, installed check is optional
        }
    }, [uuid]);

    const handleSearch = useCallback(async () => {
        if (!query || query.length < 2) return;
        clearFlashes('mods');
        setSearching(true);
        setResults([]);
        setSelected(null);
        setVersions([]);
        setSelectedVersion(null);

        try {
            await loadInstalledMods();
            const { data } = await searchMods(uuid, provider, query, mcVersion, loader);
            setResults(data.data || []);
        } catch (err) {
            addError({ key: 'mods', message: err.message || 'Error buscando mods.' });
        } finally {
            setSearching(false);
        }
    }, [uuid, provider, query, mcVersion, loader, loadInstalledMods]);

    const handleSelectMod = useCallback(async (mod) => {
        setSelected(mod);
        setSelectedVersion(null);
        setInstalledModFile(null);
        setVersions([]);
        setLoadingVersions(true);

        try {
            const { data } = await getVersions(uuid, provider, mod.id);
            const versionList = data.data || [];
            setVersions(versionList);

            // Detect which version (if any) is already installed
            for (const v of versionList) {
                if (v.filename && installedFiles.has(v.filename)) {
                    setInstalledModFile(v.filename);
                    break;
                }
            }
        } catch (err) {
            addError({ key: 'mods', message: 'Error obteniendo versiones.' });
        } finally {
            setLoadingVersions(false);
        }
    }, [uuid, provider, installedFiles]);

    const pollInstallStatus = useCallback(() => {
        statusPollRef.current = setInterval(async () => {
            try {
                const { data } = await getStatus(uuid);
                if (!data.data?.installing) {
                    clearInterval(statusPollRef.current);
                    setInstalling(false);
                    setInstallStatus('success');
                    await loadInstalledMods();
                }
            } catch {
                clearInterval(statusPollRef.current);
                setInstalling(false);
                setInstallStatus('failed');
            }
        }, 3000);
    }, [uuid, loadInstalledMods]);

    const buildDownloadUrl = useCallback((version) => {
        if (provider === 'modrinth') {
            const primaryFile = version.files?.find(f => f.primary) || version.files?.[0];
            return primaryFile?.url || null;
        }

        if (provider === 'curseforge') {
            return `https://api.curseforge.com/v1/mods/${selected.id}/files/${version.id}/download`;
        }

        return null;
    }, [provider, selected]);

    const handleInstall = useCallback(async () => {
        if (!selected || !selectedVersion) return;

        const downloadUrl = buildDownloadUrl(selectedVersion);
        if (!downloadUrl) {
            addError({ key: 'mods', message: 'No se pudo obtener la URL de descarga.' });
            return;
        }

        const filename = selectedVersion.filename || `${selected.slug}-${selectedVersion.version}.jar`;

        // Use the exact installed filename from backend if available
        let oldFilename = selected.installed_filename || null;

        clearFlashes('mods');
        setInstalling(true);
        setInstallStatus('installing');

        try {
            await installMod(uuid, provider, selected.id, selectedVersion.id, filename, downloadUrl, oldFilename);
            pollInstallStatus();
        } catch (err) {
            setInstalling(false);
            setInstallStatus('failed');
            addError({ key: 'mods', message: err.message || 'Error instalando mod.' });
        }
    }, [uuid, provider, selected, selectedVersion, buildDownloadUrl, pollInstallStatus, installedFiles]);

    const handleDeleteVersion = useCallback(async (version) => {
        if (!version.filename) return;

        setDeleting(true);
        clearFlashes('mods');

        try {
            await deleteMod(uuid, version.filename);
            await loadInstalledMods();
            if (selectedVersion?.filename === version.filename) {
                setSelectedVersion(null);
            }
            setInstallStatus('success');
        } catch (err) {
            setInstallStatus('failed');
            addError({ key: 'mods', message: err.message || 'Error eliminando mod.' });
        } finally {
            setDeleting(false);
        }
    }, [uuid, selectedVersion, loadInstalledMods]);

    return (
        <ServerContentBlock title="Mods">
            <Grid>

                {installStatus && (
                    <StatusBar status={installStatus}>
                        {installStatus === 'installing' && <Spinner size="small" />}
                        <span css={tw`text-sm`}>
                            {installStatus === 'installing' && 'Descargando mod al servidor...'}
                            {installStatus === 'success' && '✓ Mod instalado correctamente.'}
                            {installStatus === 'failed' && '✗ La instalación falló. Intenta de nuevo.'}
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

                <Card>
                    <h3 css={tw`text-neutral-200 font-semibold mb-4`}>Buscar Mods</h3>
                    <SearchFilters
                        provider={provider} setProvider={setProvider}
                        mcVersion={mcVersion} setMcVersion={setMcVersion}
                        loader={loader} setLoader={setLoader}
                        query={query} setQuery={setQuery}
                        onSearch={handleSearch} loading={searching}
                    />
                </Card>

                {results.length > 0 && (
                    <Card>
                        <h3 css={tw`text-neutral-200 font-semibold mb-4`}>
                            Resultados ({results.length})
                        </h3>
                        <div css={tw`grid gap-2 max-h-96 overflow-y-auto pr-1`}>
                            {results.map(r => (
                                <ModResult
                                    key={r.id}
                                    mod={r}
                                    selected={selected?.id === r.id}
                                    onClick={handleSelectMod}
                                />
                            ))}
                        </div>
                    </Card>
                )}

                {selected && (
                    <Card>
                        <h3 css={tw`text-neutral-200 font-semibold mb-1`}>{selected.name}</h3>
                        <p css={tw`text-sm text-neutral-400 mb-4`}>Selecciona la versión a instalar</p>
                        <VersionSelector
                            versions={versions}
                            selectedVersion={selectedVersion}
                            installedIds={Array.from(installedFiles)}
                            onSelect={setSelectedVersion}
                            onRemove={handleDeleteVersion}
                            loading={loadingVersions}
                        />
                    </Card>
                )}

                {selectedVersion && (
                    <Card>
                        <h3 css={tw`text-neutral-200 font-semibold mb-4`}>Confirmar instalación</h3>

                        <div css={tw`grid grid-cols-2 gap-3 mb-4 text-sm`}>
                            <div>
                                <span css={tw`text-neutral-500`}>Mod</span>
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

                        <Button
                            color="green"
                            onClick={handleInstall}
                            disabled={installing}
                            css={tw`w-full`}
                        >
                            {installing
                                ? <><Spinner size="small" css={tw`mr-2`} /> Instalando...</>
                                : 'Instalar Mod'
                            }
                        </Button>
                    </Card>
                )}

                {results.length === 0 && !searching && (
                    <p css={tw`text-neutral-500 text-sm text-center py-8`}>
                        Busca un mod arriba para comenzar.
                    </p>
                )}

            </Grid>
        </ServerContentBlock>
    );
}
