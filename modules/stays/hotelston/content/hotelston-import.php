<?php
/**
 * Hotelston Content Import Interface
 * This file handles the UI for importing Hotelston hotel content data
 * Standard import functionality - always available
 */
?>

<!-- API Requirements Notice -->
<div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-5 rounded">
    <div class="flex">
        <div class="flex-shrink-0">
            <span class="material-symbols-outlined text-blue-400">info</span>
        </div>
        <div class="ml-3">
            <p class="text-sm text-blue-700">
                <strong>API Requirements:</strong> This content importer requires <strong>Static Data Service API</strong> access from Hotelston. 
                If you get an error about "Web services are not enabled", contact <a href="mailto:api@hotelston.com" class="underline font-medium">api@hotelston.com</a> 
                with subject "XML [your agent name]" to request access. Note: <em>Booking Service API alone is not sufficient for content import.</em>
            </p>
        </div>
    </div>
</div>

<!-- Content Import Card -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <!-- Card Header -->
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">cloud_download</span>
                <h3 class="text-sm font-semibold text-gray-900">Content Import</h3>
            </div>
            <span id="importStatusBadge" class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-md">
                Ready
            </span>
        </div>
    </div>

    <!-- Card Body -->
    <div class="p-6">
        <!-- Import Statistics -->
        <div id="importStats" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-blue-50 rounded-lg p-4 border border-blue-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-blue-600 font-medium">Total Hotels</p>
                        <p class="text-2xl font-bold text-blue-900 mt-1" id="totalHotels">0</p>
                    </div>
                    <span class="material-symbols-outlined text-blue-400 text-3xl">hotel</span>
                </div>
            </div>

            <div class="bg-purple-50 rounded-lg p-4 border border-purple-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-purple-600 font-medium">Last Sync</p>
                        <p class="text-2xl font-bold text-purple-900 mt-1" id="lastSync">Never</p>
                    </div>
                    <span class="material-symbols-outlined text-purple-400 text-3xl">sync</span>
                </div>
            </div>

            <div class="bg-green-50 rounded-lg p-4 border border-green-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-green-600 font-medium">Destinations</p>
                        <p class="text-2xl font-bold text-green-900 mt-1" id="totalDestinations">0</p>
                    </div>
                    <span class="material-symbols-outlined text-green-400 text-3xl">location_on</span>
                </div>
            </div>

            <div class="bg-orange-50 rounded-lg p-4 border border-orange-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-orange-600 font-medium">Status</p>
                        <p class="text-2xl font-bold text-orange-900 mt-1" id="importStatus">Not Started</p>
                    </div>
                    <span class="material-symbols-outlined text-orange-400 text-3xl">info</span>
                </div>
            </div>
        </div>

        <!-- Import Mode Selection -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Import Mode</label>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="relative">
                    <input type="radio" name="import_mode" id="mode_fresh" value="fresh" class="peer hidden">
                    <label for="mode_fresh" class="block p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-blue-300 peer-checked:border-blue-500 peer-checked:bg-blue-50 transition-all">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-blue-500 text-2xl">refresh</span>
                            <div>
                                <h4 class="font-semibold text-gray-900">Fresh Install</h4>
                                <p class="text-xs text-gray-600 mt-1">Delete all existing data and import fresh content from Hotelston API</p>
                            </div>
                        </div>
                    </label>
                </div>

                <div class="relative">
                    <input type="radio" name="import_mode" id="mode_update" value="update" class="peer hidden" checked>
                    <label for="mode_update" class="block p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-green-300 peer-checked:border-green-500 peer-checked:bg-green-50 transition-all">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-green-500 text-2xl">update</span>
                            <div>
                                <h4 class="font-semibold text-gray-900">Update Mode</h4>
                                <p class="text-xs text-gray-600 mt-1">Update existing records and add new ones without deleting current data</p>
                            </div>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- Progress Section (Hidden by default) -->
        <div id="progressSection" class="hidden mb-6">
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-700">Overall Progress</span>
                        <span class="text-sm font-semibold text-blue-600" id="progressPercentage">0%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                        <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full transition-all duration-300 flex items-center justify-center" style="width: 0%">
                            <span class="text-xs text-white font-medium" id="progressBarText"></span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-500 text-lg">animation</span>
                        <div>
                            <p class="text-xs text-gray-500">Current Operation</p>
                            <p class="text-sm font-medium text-gray-900" id="currentOperation">Processing...</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-green-500 text-lg">inventory_2</span>
                        <div>
                            <p class="text-xs text-gray-500">Chunk Progress</p>
                            <p class="text-sm font-medium text-gray-900">
                                <span id="currentChunk">0</span> of <span id="totalChunks">173</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-purple-500 text-lg">schedule</span>
                        <div>
                            <p class="text-xs text-gray-500">ETA</p>
                            <p class="text-sm font-medium text-gray-900" id="eta">Calculating...</p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between text-sm text-gray-600">
                    <span><span id="processedRecords">0</span> of <span id="totalRecords">50000</span> records</span>
                    <span>Elapsed: <span id="elapsedTime">0s</span></span>
                </div>
            </div>

            <!-- Terminal Console -->
            <div class="mt-4 bg-gray-900 rounded-lg overflow-hidden border border-gray-700">
                <div class="bg-gray-800 px-4 py-2 flex items-center justify-between border-b border-gray-700">
                    <div class="flex items-center space-x-2">
                        <div class="flex space-x-2">
                            <div class="w-3 h-3 rounded-full bg-red-500"></div>
                            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                        </div>
                        <span class="text-gray-300 text-xs font-mono ml-4">Import Console</span>
                    </div>
                </div>
                <div id="consoleOutput" class="p-4 font-mono text-xs text-green-400 h-48 overflow-y-auto">
                    <div class="text-cyan-400">[INIT] Import process starting...</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-3">
            <button id="startImportBtn" onclick="startImport()" class="btn flex-1">
                <span class="material-symbols-outlined text-sm">play_arrow</span>
                Start Import
            </button>
            <button id="pauseImportBtn" onclick="pauseImport()" class="btn light hidden">
                <span class="material-symbols-outlined text-sm">pause</span>
                Pause
            </button>
            <button id="cancelImportBtn" onclick="cancelImport()" class="btn light hidden">
                <span class="material-symbols-outlined text-sm">cancel</span>
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
let importInterval = null;
let isPaused = false;

// Load statistics on page load
document.addEventListener('DOMContentLoaded', function() {
    loadImportStats();
});

function loadImportStats() {
    fetch('<?= root ?>modules/hotels/hotelston/stats')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('totalHotels').textContent = data.data.total_hotels.toLocaleString();
                document.getElementById('totalDestinations').textContent = data.data.total_destinations.toLocaleString();
                
                if (data.data.last_import && data.data.last_import.started_at) {
                    const date = new Date(data.data.last_import.started_at);
                    document.getElementById('lastSync').textContent = date.toLocaleDateString();
                    document.getElementById('importStatus').textContent = data.data.last_import.status;
                }
            }
        })
        .catch(error => console.error('Error loading stats:', error));
}

function startImport() {
    const mode = document.querySelector('input[name="import_mode"]:checked').value;
    const startBtn = document.getElementById('startImportBtn');
    const progressSection = document.getElementById('progressSection');
    
    // Get credentials from form
    const c1 = document.querySelector('input[name="c1"]').value;
    const c2 = document.querySelector('input[name="c2"]').value;
    const c3 = document.querySelector('input[name="c3"]').value || '0';
    const env = document.getElementById('dev_mode_status').value === '1' ? 'test' : 'production';
    
    if (!c1 || !c2) {
        vt.error('Please enter your Hotelston credentials first');
        return;
    }
    
    startBtn.disabled = true;
    startBtn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> Initializing...';
    
    progressSection.classList.remove('hidden');
    document.getElementById('importStatusBadge').textContent = 'Running';
    document.getElementById('importStatusBadge').classList.remove('bg-gray-100', 'text-gray-500');
    document.getElementById('importStatusBadge').classList.add('bg-blue-100', 'text-blue-600');
    
    addConsoleLog('[START] Initializing import process...');
    addConsoleLog('[MODE] Import mode: ' + mode.toUpperCase());
    
    const formData = new FormData();
    formData.append('mode', mode);
    formData.append('c1', c1);
    formData.append('c2', c2);
    formData.append('c3', c3);
    formData.append('env', env);
    
    fetch('<?= root ?>modules/hotels/hotelston/content_import', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            addConsoleLog('[SUCCESS] Import started successfully');
            addConsoleLog('[IMPORT ID] ' + data.import_id);
            
            document.getElementById('pauseImportBtn').classList.remove('hidden');
            document.getElementById('cancelImportBtn').classList.remove('hidden');
            startBtn.classList.add('hidden');
            
            // Start polling for progress
            importInterval = setInterval(checkProgress, 2000);
            
            // Start processing chunks
            processNextChunk();
        } else {
            addConsoleLog('[ERROR] ' + data.message, 'text-red-400');
            vt.error(data.message);
            resetImportUI();
        }
    })
    .catch(error => {
        addConsoleLog('[ERROR] ' + error.message, 'text-red-400');
        vt.error('Failed to start import: ' + error.message);
        resetImportUI();
    });
}

function processNextChunk() {
    if (isPaused) return;
    
    fetch('<?= root ?>modules/hotels/hotelston/process', {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.continue && !isPaused) {
            setTimeout(processNextChunk, 100);
        } else if (data.completed) {
            if (data.success) {
                addConsoleLog('[COMPLETE] Import finished successfully!', 'text-green-400');
                vt.success('Import completed successfully! Processed ' + data.total_processed + ' records');
            } else {
                addConsoleLog('[ERROR] Import failed: ' + data.error, 'text-red-400');
                vt.error('Import failed: ' + data.error);
            }
            clearInterval(importInterval);
            resetImportUI();
            loadImportStats();
        }
    })
    .catch(error => {
        addConsoleLog('[ERROR] ' + error.message, 'text-red-400');
        clearInterval(importInterval);
        resetImportUI();
    });
}

function checkProgress() {
    fetch('<?= root ?>modules/hotels/hotelston/progress')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('progressPercentage').textContent = data.progress.toFixed(1) + '%';
                document.getElementById('progressBar').style.width = data.progress + '%';
                document.getElementById('progressBarText').textContent = data.progress.toFixed(0) + '%';
                
                document.getElementById('currentOperation').textContent = data.current_operation;
                document.getElementById('currentChunk').textContent = data.chunk;
                document.getElementById('totalChunks').textContent = data.total_chunks;
                document.getElementById('processedRecords').textContent = data.processed.toLocaleString();
                document.getElementById('totalRecords').textContent = data.total.toLocaleString();
                
                const etaMinutes = Math.ceil(data.estimated_remaining / 60);
                document.getElementById('eta').textContent = etaMinutes + ' min';
                
                const elapsedMinutes = Math.floor(data.elapsed_time / 60);
                const elapsedSeconds = data.elapsed_time % 60;
                document.getElementById('elapsedTime').textContent = `${elapsedMinutes}m ${elapsedSeconds}s`;
                
                // Update console with new logs
                if (data.logs && data.logs.length > 0) {
                    data.logs.slice(-5).forEach(log => addConsoleLog(log));
                }
            }
        })
        .catch(error => console.error('Error checking progress:', error));
}

function pauseImport() {
    isPaused = !isPaused;
    const pauseBtn = document.getElementById('pauseImportBtn');
    
    if (isPaused) {
        pauseBtn.innerHTML = '<span class="material-symbols-outlined text-sm">play_arrow</span> Resume';
        addConsoleLog('[PAUSE] Import paused by user', 'text-yellow-400');
        clearInterval(importInterval);
    } else {
        pauseBtn.innerHTML = '<span class="material-symbols-outlined text-sm">pause</span> Pause';
        addConsoleLog('[RESUME] Import resumed', 'text-cyan-400');
        importInterval = setInterval(checkProgress, 2000);
        processNextChunk();
    }
}

function cancelImport() {
    if (!confirm('Are you sure you want to cancel the import? Progress will be lost.')) {
        return;
    }
    
    fetch('<?= root ?>modules/hotels/hotelston/cancel', {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        addConsoleLog('[CANCEL] Import cancelled by user', 'text-red-400');
        vt.info('Import cancelled');
        clearInterval(importInterval);
        resetImportUI();
        loadImportStats();
    });
}

function resetImportUI() {
    const startBtn = document.getElementById('startImportBtn');
    startBtn.disabled = false;
    startBtn.innerHTML = '<span class="material-symbols-outlined text-sm">play_arrow</span> Start Import';
    startBtn.classList.remove('hidden');
    
    document.getElementById('pauseImportBtn').classList.add('hidden');
    document.getElementById('cancelImportBtn').classList.add('hidden');
    
    document.getElementById('importStatusBadge').textContent = 'Ready';
    document.getElementById('importStatusBadge').classList.remove('bg-blue-100', 'text-blue-600');
    document.getElementById('importStatusBadge').classList.add('bg-gray-100', 'text-gray-500');
    
    isPaused = false;
}

function addConsoleLog(message, colorClass = 'text-green-400') {
    const console = document.getElementById('consoleOutput');
    const logEntry = document.createElement('div');
    logEntry.className = colorClass;
    logEntry.textContent = message;
    console.appendChild(logEntry);
    console.scrollTop = console.scrollHeight;
}
</script>
