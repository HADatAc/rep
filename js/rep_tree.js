
(function ($, Drupal) {
    Drupal.behaviors.tree = {
        attach: function (context, settings) {
            const nodeUriMap = new Map();
            let nodeIdCounter = 0;
            const expandedNodes = new Set(); // Track expanded nodes

            // Event delegation for dynamically loading children
            $(document).off('click', '.node').on('click', '.node', function (event) {
                event.stopPropagation();

                const $node = $(this);
                const nodeUri = $node.data('uri');
                const nodeId = 'node-' + $node.data('nodeId');
                //console.log(nodeUri);
                console.log(nodeId);
                //let nodeId = $node.data('id');
                //if (!nodeId) {
                //    nodeId = `node-${nodeIdCounter++}`;
                //    $node.data('id', nodeId);
                //    //$node.css('font-weight', 'bold');
                //    nodeUriMap.set(nodeId, nodeUri); // Map nodeId to nodeUri
                //}

                //const $childrenContainer = $(`#tree-container`);
                const $childrenContainer = $(`#children-${nodeId}`);

                if (expandedNodes.has(nodeId)) {
                    // Node is already expanded, so collapse it
                    $childrenContainer.empty(); // Remove children
                    expandedNodes.delete(nodeId); // Update state
                    $node.css('font-weight', 'normal'); // Optionally change style
                } else {
                    // Load children only if not already loaded
                    if ($childrenContainer.children().length === 0) {
                        //console.log(nodeUri);
                        $.ajax({
                            url: 'http://localhost/rep/getchildren',
                            type: 'GET',
                            data: { nodeUri: nodeUri },
                            dataType: 'json',
                            success: function (data) {
                                //console.log('AJAX Success:', data);
                                if (Array.isArray(data) && data.length > 0) {
                                    //console.log(renderTreeNodes(data));
                                    $childrenContainer.html(renderTreeNodes(data));
                                    expandedNodes.add(nodeId); // Update state
                                } else {
                                    $node.css('text-decoration-line', 'underline');
                                }
                            },
                            error: function (jqXHR, textStatus, errorThrown) {
                                console.error('Failed to fetch data:', textStatus, errorThrown);
                            }
                        });
                    } 
                }
            });

            function renderTreeNodes(nodes) {
                return '<ul>' + nodes.map(renderNode).join('') + '</ul>';
            }

            function renderNode(node) {
                //const nodeId = `node-${nodeIdCounter++}`;
                const nodeId = `node-${node.nodeId}`
                //nodeUriMap.set(nodeId, node.uri);
                //const shortHash = hashUriToShortString(node.uri);
                //console.log(node.uri);
                //console.log(nodeId);
                //console.log(shortHash);

                let nodeHtml = '<li>';
                nodeHtml += `<span class="node" data-node-id="${node.nodeId}" data-uri="${node.uri}">${node.label || node.name}</span>&nbsp;`;
                nodeHtml += `<input type="checkbox" class="node-checkbox" data-node-id="${node.nodeId}">`; // Add the checkbox
                nodeHtml += '<div class="children" id="children-' + nodeId + '">';
                if (node.children && node.children.length > 0) {
                    nodeHtml += renderTreeNodes(node.children);
                }
                nodeHtml += '</div>';
                nodeHtml += '</li>';
                return nodeHtml;
            }

            // Event delegation for select button
            $(document).on('click', '.select-btn', function (event) {
                event.preventDefault(); // Prevent default action

                const $button = $(this);
                const nodeId = $button.data('id');
                const $node = $(`[data-id="${nodeId}"]`);
                const nodeUri = $node.data('uri');

                console.log(`Node selected: ${nodeUri}`);
                // Perform the action for selecting the node
            });
            
            async function hashUriToShortString(uri, length = 5) {
                // Encode the URI as a Uint8Array
                const encoder = new TextEncoder();
                const data = encoder.encode(uri);
    
                // Generate the SHA-256 hash
                const hashBuffer = await crypto.subtle.digest('SHA-256', data);
                const hashArray = Array.from(new Uint8Array(hashBuffer));
    
                // Convert the hash to a hexadecimal string
                const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    
                // Convert the hexadecimal string to a base62 string
                const base62 = base62Encode(hashHex);
    
                // Truncate to the desired length
                return base62.slice(0, length);
            }
    
            // Base62 encoding function
            function base62Encode(hex) {
                const base62Chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                let decimal = BigInt('0x' + hex);
                let base62 = '';
                
                while (decimal > 0) {
                    const remainder = Number(decimal % 62n);
                    base62 = base62Chars[remainder] + base62;
                    decimal = decimal / 62n;
                }
                
                return base62.padStart(hex.length, '0');
            }

        }
    };
})(jQuery, Drupal);