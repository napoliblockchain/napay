// SIP-021 fase 3: pagatore di test sulla chain usa-e-getta. Legge da stdin un piano JSON
// [{label, to, cents, ref|null}] e invia transfer TTS con la chiave del deployer della chain di test,
// accodando il riferimento (16 byte) ai dati della chiamata. Stampa [{label, tx}] su stdout. Solo test.
import { readFileSync } from 'node:fs';
import { JsonRpcProvider, Wallet, Interface, concat } from 'ethers';

const plan = JSON.parse(readFileSync(0, 'utf8'));
const provider = new JsonRpcProvider(process.env.CHAIN_RPC_URL, undefined, { staticNetwork: true });
const { address } = JSON.parse(readFileSync('/data/tts.json', 'utf8'));
const payer = new Wallet(readFileSync('/data/key', 'utf8').trim(), provider);
const iface = new Interface(['function transfer(address,uint256) returns (bool)']);
const out = [];
let nonce = await provider.getTransactionCount(payer.address, 'pending');
for (const item of plan) {
    const call = iface.encodeFunctionData('transfer', [item.to, BigInt(item.cents)]);
    const data = item.ref ? concat([call, '0x' + item.ref]) : call;
    const tx = await payer.sendTransaction({ to: address, data, gasPrice: 0n, gasLimit: 200000n, nonce: nonce++ });
    out.push({ label: item.label, tx: tx.hash, wait: tx });
}
for (const item of out) {
    const receipt = await item.wait.wait();
    if (receipt.status !== 1) throw new Error('transfer failed: ' + item.label);
    delete item.wait;
}
process.stdout.write(JSON.stringify(out));
