// ============================================================
// CaféOS — Thermal Printer Module
// File: electron/printer.js
// ============================================================
// Supports two connection types:
//   - Network (IP-based): Most common for modern POS printers
//     e.g. Epson TM-T82, TVS RP-3160, Xprinter XP-N160I
//   - USB: For printers connected via USB cable
//
// ESC/POS is the standard protocol used by 95% of thermal
// receipt printers. This module builds ESC/POS byte commands
// manually so no native driver is needed.
//
// SUPPORTED PRINTERS (tested):
//   Epson TM-T20, TM-T82, TM-T88 (USB & Network)
//   TVS RP-3160, RP-3200 (USB & Network)
//   Xprinter XP-N160I, XP-Q300 (Network)
//   Any generic ESC/POS 80mm thermal printer
// ============================================================

const net  = require('net');
const { exec } = require('child_process');

const logger = require('./logger');

// ── ESC/POS command constants ─────────────────────────────────
const ESC = 0x1B;
const GS  = 0x1D;
const CMD = {
  INIT:          Buffer.from([ESC, 0x40]),               // Initialize printer
  ALIGN_LEFT:    Buffer.from([ESC, 0x61, 0x00]),
  ALIGN_CENTER:  Buffer.from([ESC, 0x61, 0x01]),
  ALIGN_RIGHT:   Buffer.from([ESC, 0x61, 0x02]),
  BOLD_ON:       Buffer.from([ESC, 0x45, 0x01]),
  BOLD_OFF:      Buffer.from([ESC, 0x45, 0x00]),
  DOUBLE_HEIGHT: Buffer.from([ESC, 0x21, 0x10]),
  NORMAL_SIZE:   Buffer.from([ESC, 0x21, 0x00]),
  UNDERLINE_ON:  Buffer.from([ESC, 0x2D, 0x01]),
  UNDERLINE_OFF: Buffer.from([ESC, 0x2D, 0x00]),
  CUT:           Buffer.from([GS,  0x56, 0x41, 0x03]),   // Partial cut
  FEED_3:        Buffer.from([ESC, 0x64, 0x03]),          // Feed 3 lines
  FEED_5:        Buffer.from([ESC, 0x64, 0x05]),
  OPEN_DRAWER:   Buffer.from([ESC, 0x70, 0x00, 0x19, 0x19]), // Open cash drawer
  BEEP:          Buffer.from([ESC, 0x42, 0x01, 0x01]),    // Beep once
};

// ── Build receipt byte buffer ─────────────────────────────────
function buildReceiptBuffer(data, config) {
  const width      = config.receiptWidth || 48;  // chars per line
  const cafeName   = config.cafeName     || 'My Cafe';
  const cafeAddr   = config.cafeAddress  || '';
  const cafePhone  = config.cafePhone    || '';
  const footer     = config.receiptFooter || 'Thank you!';

  const parts = [];

  const text = (str) => Buffer.from(str + '\n', 'utf8');
  const line = (char = '-') => text(char.repeat(width));
  const center = (str) => {
    const pad = Math.max(0, Math.floor((width - str.length) / 2));
    return text(' '.repeat(pad) + str);
  };
  const row = (left, right) => {
    const rightStr = String(right);
    const leftWidth = width - rightStr.length - 1;
    const leftStr = String(left).substring(0, leftWidth).padEnd(leftWidth);
    return text(`${leftStr} ${rightStr}`);
  };
  const blank = () => text('');

  // ── Header ─────────────────────────────────────────────────
  parts.push(CMD.INIT);
  parts.push(CMD.ALIGN_CENTER);
  parts.push(CMD.BOLD_ON);
  parts.push(CMD.DOUBLE_HEIGHT);
  parts.push(text(cafeName));
  parts.push(CMD.NORMAL_SIZE);
  parts.push(CMD.BOLD_OFF);

  if (cafeAddr)  parts.push(center(cafeAddr));
  if (cafePhone) parts.push(center(cafePhone));
  parts.push(blank());

  parts.push(CMD.ALIGN_LEFT);
  parts.push(line('-'));

  // ── Bill info ─────────────────────────────────────────────
  parts.push(row('Bill No:',     data.billNumber || ''));
  parts.push(row('Order:',       data.orderNumber || ''));
  parts.push(row('Table:',       data.tableNumber || 'Takeaway'));
  parts.push(row('Date:',        formatDate(data.date || new Date())));
  if (data.cashierName) parts.push(row('Cashier:', data.cashierName));
  if (data.guestCount)  parts.push(row('Guests:',  data.guestCount));
  parts.push(line('-'));

  // ── Items ──────────────────────────────────────────────────
  (data.items || []).forEach(item => {
    // Item name on its own line if long
    const itemLine = `${item.name} x${item.quantity}`;
    const price    = `Rs.${parseFloat(item.subtotal).toFixed(2)}`;
    parts.push(row(itemLine, price));
    if (item.notes) {
      parts.push(text(`  > ${item.notes}`));
    }
  });
  parts.push(line('-'));

  // ── Totals ─────────────────────────────────────────────────
  parts.push(row('Subtotal:',   `Rs.${parseFloat(data.subtotal || 0).toFixed(2)}`));

  if (data.discountAmount > 0) {
    const discLabel = data.discountType === 'percent'
      ? `Discount (${data.discountValue}%):`
      : 'Discount:';
    parts.push(row(discLabel, `-Rs.${parseFloat(data.discountAmount).toFixed(2)}`));
  }

  parts.push(row(`Tax (GST ${data.taxRate || 0}%):`, `Rs.${parseFloat(data.taxAmount || 0).toFixed(2)}`));
  parts.push(line('='));

  parts.push(CMD.BOLD_ON);
  parts.push(CMD.DOUBLE_HEIGHT);
  parts.push(row('TOTAL:', `Rs.${parseFloat(data.grandTotal || 0).toFixed(2)}`));
  parts.push(CMD.NORMAL_SIZE);
  parts.push(CMD.BOLD_OFF);

  parts.push(row('Payment:', (data.paymentMethod || 'Cash').toUpperCase()));
  if (data.amountTendered > 0) {
    parts.push(row('Tendered:', `Rs.${parseFloat(data.amountTendered).toFixed(2)}`));
  }
  if (data.changeDue > 0) {
    parts.push(CMD.BOLD_ON);
    parts.push(row('Change:', `Rs.${parseFloat(data.changeDue).toFixed(2)}`));
    parts.push(CMD.BOLD_OFF);
  }

  // ── Footer ─────────────────────────────────────────────────
  parts.push(blank());
  parts.push(CMD.ALIGN_CENTER);
  parts.push(line('-'));
  parts.push(text(footer));
  parts.push(blank());

  // ── Cut ────────────────────────────────────────────────────
  parts.push(CMD.FEED_5);
  parts.push(CMD.CUT);

  return Buffer.concat(parts);
}

// ── Network print ─────────────────────────────────────────────
function printNetwork(buffer, ip, port = 9100) {
  return new Promise((resolve, reject) => {
    const client = new net.Socket();
    const timeout = 6000;

    client.setTimeout(timeout);

    client.connect(port, ip, () => {
      logger.info('Connected to printer', { ip, port });
      client.write(buffer, (err) => {
        if (err) {
          client.destroy();
          reject(err);
          return;
        }
        // Small delay to ensure printer receives all data before close
        setTimeout(() => {
          client.end();
          resolve({ method: 'network', ip, port });
        }, 500);
      });
    });

    client.on('timeout', () => {
      client.destroy();
      reject(new Error(`Printer connection timeout — check IP ${ip}:${port}`));
    });

    client.on('error', (err) => {
      client.destroy();
      reject(new Error(`Printer error: ${err.message}`));
    });
  });
}

// ── USB print (Windows via raw print command) ─────────────────
function printUSBWindows(buffer, printerName) {
  return new Promise((resolve, reject) => {
    const fs  = require('fs');
    const tmp = require('os').tmpdir();
    const file = require('path').join(tmp, `receipt_${Date.now()}.bin`);

    fs.writeFile(file, buffer, (writeErr) => {
      if (writeErr) { reject(writeErr); return; }

      const cmd = printerName
        ? `print /D:"${printerName}" "${file}"`
        : `copy /b "${file}" LPT1:`;

      exec(cmd, (err) => {
        fs.unlink(file, () => {});
        if (err) reject(new Error(`Print command failed: ${err.message}`));
        else     resolve({ method: 'usb', printerName });
      });
    });
  });
}

// ── USB print (Linux/Mac via lp command) ─────────────────────
function printUSBUnix(buffer, printerName) {
  return new Promise((resolve, reject) => {
    const fs   = require('fs');
    const tmp  = require('os').tmpdir();
    const file = require('path').join(tmp, `receipt_${Date.now()}.bin`);

    fs.writeFile(file, buffer, (writeErr) => {
      if (writeErr) { reject(writeErr); return; }

      const cmd = printerName
        ? `lp -d "${printerName}" "${file}"`
        : `cat "${file}" > /dev/usb/lp0`;

      exec(cmd, (err) => {
        fs.unlink(file, () => {});
        if (err) reject(new Error(`lp print failed: ${err.message}`));
        else     resolve({ method: 'usb-unix', printerName });
      });
    });
  });
}

// ── List available printers ───────────────────────────────────
function listPrinters() {
  return new Promise((resolve) => {
    if (process.platform === 'win32') {
      exec('wmic printer get name', (err, stdout) => {
        if (err) { resolve([]); return; }
        const names = stdout.split('\n').slice(1).map(l => l.trim()).filter(Boolean);
        resolve(names);
      });
    } else {
      exec('lpstat -p -d 2>/dev/null | grep "^printer"', (err, stdout) => {
        if (err) { resolve([]); return; }
        const names = stdout.split('\n').map(l => l.split(' ')[1]).filter(Boolean);
        resolve(names);
      });
    }
  });
}

// ── Test print ────────────────────────────────────────────────
async function printTest(config) {
  const testData = {
    billNumber:    'TEST-001',
    orderNumber:   'ORD-TEST-001',
    tableNumber:   'T1',
    date:          new Date(),
    cashierName:   'Test User',
    guestCount:    2,
    items: [
      { name: 'Cappuccino', quantity: 2, subtotal: 300, notes: 'No sugar' },
      { name: 'Club Sandwich', quantity: 1, subtotal: 280 },
    ],
    subtotal:       580,
    discountAmount: 0,
    discountType:   'none',
    discountValue:  0,
    taxRate:        5,
    taxAmount:      29,
    grandTotal:     609,
    paymentMethod:  'Cash',
    amountTendered: 700,
    changeDue:      91,
  };

  return printReceipt(testData, config);
}

// ── Main print function ───────────────────────────────────────
async function printReceipt(data, config) {
  const printerType = config.printerType || 'network';
  const buffer      = buildReceiptBuffer(data, config);

  logger.info('Printing receipt', {
    type:      printerType,
    billNum:   data.billNumber,
    bufferLen: buffer.length,
  });

  if (printerType === 'network') {
    const ip   = config.printerIp   || '192.168.1.100';
    const port = config.printerPort || 9100;
    return printNetwork(buffer, ip, port);
  } else if (printerType === 'usb') {
    const printerName = config.printerName || null;
    if (process.platform === 'win32') {
      return printUSBWindows(buffer, printerName);
    } else {
      return printUSBUnix(buffer, printerName);
    }
  } else {
    throw new Error(`Unknown printer type: ${printerType}. Use 'network' or 'usb'.`);
  }
}

// ── Helpers ───────────────────────────────────────────────────
function formatDate(d) {
  const date = new Date(d);
  return date.toLocaleString('en-IN', {
    day:    '2-digit', month: 'short', year:   'numeric',
    hour:   '2-digit', minute: '2-digit', hour12: true,
  });
}

module.exports = { printReceipt, printTest, listPrinters, buildReceiptBuffer };
