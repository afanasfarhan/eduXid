/* eduXid LJK Excel V1 — OOXML workbook offline; no CDN, no external dependency. */
(() => {
  'use strict';
  if (window.eduXidLjkExcel) return;
  const encoder = new TextEncoder();
  const xml = (x) => String(x ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;').replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, '');
  const safe = (x) => String(x || 'paket-ujian').normalize('NFKD').replace(/[^\w\s.-]+/g, '').replace(/\s+/g, '-').slice(0, 65) || 'paket-ujian';
  const U16 = (n) => [n & 255, (n >>> 8) & 255];
  const U32 = (n) => [n & 255, (n >>> 8) & 255, (n >>> 16) & 255, (n >>> 24) & 255];
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n++) { let c = n; for (let b = 0; b < 8; b++) c = c & 1 ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1); table[n] = c >>> 0; }
  function crc(bytes) { let c = 0xFFFFFFFF; for (const b of bytes) c = table[(c ^ b) & 255] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; }
  function zip(files) {
    const chunks = [], central = []; let offset = 0;
    for (const [path, content] of Object.entries(files)) {
      const name = encoder.encode(path), data = encoder.encode(content), hash = crc(data);
      const local = Uint8Array.from([80,75,3,4,20,0,0,8,0,0,0,0,33,0,...U32(hash),...U32(data.length),...U32(data.length),...U16(name.length),0,0]);
      const entry = Uint8Array.from([80,75,1,2,20,0,20,0,0,8,0,0,0,0,33,0,...U32(hash),...U32(data.length),...U32(data.length),...U16(name.length),0,0,0,0,0,0,0,0,0,0,0,0,...U32(offset)]);
      chunks.push(local,name,data); central.push(entry,name); offset += local.length + name.length + data.length;
    }
    const centralSize = central.reduce((n,b)=>n+b.length,0);
    return new Blob([...chunks,...central,Uint8Array.from([80,75,5,6,0,0,0,0,...U16(Object.keys(files).length),...U16(Object.keys(files).length),...U32(centralSize),...U32(offset),0,0])], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
  }
  function cell(ref, value, style=0, number=false) {
    const s = style ? ` s="${style}"` : '';
    if (number && Number.isFinite(Number(value))) return `<c r="${ref}"${s}><v>${Number(value)}</v></c>`;
    return `<c r="${ref}" t="inlineStr"${s}><is><t xml:space="preserve">${xml(value)}</t></is></c>`;
  }
  function workbookData(rows, title, options={}) {
    const heading = ['No.','Nama Murid','Kelas','NISN','Skor Betul','Nilai','Status'];
    const date = new Date(); const dateLabel = date.toLocaleString('id-ID');
    const full = [
      `<row r="1" ht="35" customHeight="1">${cell('A1','HASIL KOREKSI LJK',1)}</row>`,
      `<row r="2" ht="26" customHeight="1">${cell('A2','Paket Ujian: '+String(title||'Paket Ujian'),2)}</row>`,
      `<row r="3" ht="25" customHeight="1">${cell('A3','Diekspor: '+dateLabel+'  |  Jumlah hasil: '+rows.length,2)}</row>`,
      `<row r="4" ht="11" customHeight="1"></row>`,
      `<row r="5" ht="30" customHeight="1">${heading.map((x,i)=>cell(String.fromCharCode(65+i)+'5',x,3)).join('')}</row>`
    ];
    rows.forEach((r,i)=>{
      const idx = i+6, cs = [i+1,r.student_name||'Belum dikenali',r.class_name||r.kelas||'-',String(r.nisn||''),(Number(r.correct_count)||0)+'/'+(Number(r.question_count)||0),Number(r.score)||0,r.status_label||r.status||'-'];
      full.push(`<row r="${idx}" ht="24" customHeight="1">${cs.map((val,j)=>cell(String.fromCharCode(65+j)+idx,val,j===5?6:(i%2===0?4:5),j===0||j===5)).join('')}</row>`);
    });
    const summaryRow=rows.length+7;
    full.push(`<row r="${summaryRow}" ht="28" customHeight="1">${cell('B'+summaryRow,'JUMLAH HASIL',7)}${cell('F'+summaryRow,rows.length,7,true)}</row>`);
    const sheet=`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:G${summaryRow}"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols><col min="1" max="1" width="7" customWidth="1"/><col min="2" max="2" width="33" customWidth="1"/><col min="3" max="3" width="19" customWidth="1"/><col min="4" max="4" width="18" customWidth="1"/><col min="5" max="5" width="17" customWidth="1"/><col min="6" max="6" width="12" customWidth="1"/><col min="7" max="7" width="37" customWidth="1"/></cols><sheetData>${full.join('')}</sheetData><mergeCells count="3"><mergeCell ref="A1:G1"/><mergeCell ref="A2:G2"/><mergeCell ref="A3:G3"/></mergeCells><autoFilter ref="A5:G${Math.max(5,rows.length+5)}"/><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1"/></worksheet>`;
    const styles=`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="17"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF334155"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEEF2F7"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/><xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1"/><xf numFmtId="2" fontId="2" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`;
    return {
      '[Content_Types].xml':'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
      '_rels/.rels':'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
      'xl/workbook.xml':'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hasil Koreksi LJK" sheetId="1" r:id="rId1"/></sheets></workbook>',
      'xl/_rels/workbook.xml.rels':'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
      'xl/styles.xml':styles,
      'xl/worksheets/sheet1.xml':sheet,
    };
  }
  function exportResults(rows, title, options={}) {
    if (!Array.isArray(rows) || rows.length === 0) throw new Error('Belum ada hasil koreksi untuk diekspor.');
    const blob=zip(workbookData(rows,title,options)), url=URL.createObjectURL(blob), link=document.createElement('a');
    link.href=url; link.download='Hasil-Koreksi-LJK-'+safe(title)+'-'+new Date().toISOString().slice(0,10)+'.xlsx';
    document.body.appendChild(link); link.click(); link.remove(); setTimeout(()=>URL.revokeObjectURL(url),30000);
    return blob;
  }
  window.eduXidLjkExcel = {exportResults, workbookData};
})();
