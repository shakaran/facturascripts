<?php
/**
 * This file is part of facturacion_base
 * Copyright (C) 2013-2017  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Core\Model;

/**
 * El cliente. Puede tener una o varias direcciones y subcuentas asociadas.
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class Cliente
{

    use Base\ModelTrait;
    use Base\Persona;

    /**
     * TODO
     * @var array
     */
    private static $regimenes_iva;

    /**
     * Clave primaria. Varchar (6).
     * @var string
     */
    public $codcliente;

    /**
     * Grupo al que pertenece el cliente.
     * @var string
     */
    public $codgrupo;

    /**
     * TRUE -> el cliente ya no nos compra o no queremos nada con él.
     * @var boolean
     */
    public $debaja;

    /**
     * Fecha en la que se dió de baja al cliente.
     * @var string
     */
    public $fechabaja;

    /**
     * Régimen de fiscalidad del cliente. Por ahora solo están implementados
     * general y exento.
     * @var string
     */
    public $regimeniva;

    /**
     * TRUE -> al cliente se le aplica recargo de equivalencia.
     * @var boolean
     */
    public $recargo;

    /**
     * Dias de pago preferidos a la hora de calcular el vencimiento de las facturas.
     * Días separados por comas: 1,15,31
     * @var string
     */
    public $diaspago;

    /**
     * Proveedor asociado equivalente
     * @var string
     */
    public $codproveedor;

    public function tableName()
    {
        return 'clientes';
    }

    public function primaryColumn()
    {
        return 'codcliente';
    }
    
    public function install()
    {
        /// necesitamos la tabla de grupos comprobada para la clave ajena
        new GrupoClientes();
        
        return '';
    }

    /**
     * Resetea los valores de todas las propiedades modelo.
     */
    public function clear()
    {
        $this->codcliente = null;
        $this->nombre = '';
        $this->razonsocial = '';
        // $this->tipoidfiscal = FS_CIFNIF;
        $this->cifnif = '';
        $this->telefono1 = '';
        $this->telefono2 = '';
        $this->fax = '';
        $this->email = '';
        $this->web = '';

        /**
         * Ponemos por defecto la serie a NULL para que en las nuevas ventas
         * a este cliente se utilice la serie por defecto de la empresa.
         * NULL => usamos la serie de la empresa.
         */
        $this->codserie = null;

        $this->coddivisa = $this->defaultItems->codDivisa();
        $this->codpago = $this->defaultItems->codPago();
        $this->codagente = null;
        $this->codgrupo = null;
        $this->debaja = false;
        $this->fechabaja = null;
        $this->fechaalta = date('d-m-Y');
        $this->observaciones = null;
        $this->regimeniva = 'General';
        $this->recargo = false;
        $this->personafisica = true;
        $this->diaspago = null;
        $this->codproveedor = null;
    }

    /**
     * Devuelve un array con los regimenes de iva disponibles.
     * @return array
     */
    public function regimenesIva()
    {
        if (self::$regimenes_iva === null) {
            /// Si hay usa lista personalizada en fs_vars, la usamos
            $fsvar = new FsVar();
            $data = $fsvar->simpleGet('cliente::regimenes_iva');
            if (!empty($data)) {
                self::$regimenes_iva = [];
                foreach (explode(',', $data) as $d) {
                    self::$regimenes_iva[] = trim($d);
                }
            } else {
                /// sino usamos estos
                self::$regimenes_iva = ['General', 'Exento'];
            }

            /// además de añadir los que haya en la base de datos
            $sql = 'SELECT DISTINCT regimeniva FROM clientes ORDER BY regimeniva ASC;';
            $data = $this->dataBase->select($sql);
            if (!empty($data)) {
                foreach ($data as $d) {
                    if (!in_array($d['regimeniva'], self::$regimenes_iva, false)) {
                        self::$regimenes_iva[] = $d['regimeniva'];
                    }
                }
            }
        }

        return self::$regimenes_iva;
    }

    /**
     * Devuelve el primer cliente que tenga $cifnif como cifnif.
     * Si el cifnif está en blanco y se proporciona una razón social,
     * se devuelve el primer cliente que tenga esa razón social.
     *
     * @param string $cifnif
     * @param string $razon
     *
     * @return bool|Cliente
     */
    public function getByCifnif($cifnif, $razon = '')
    {
        if ($cifnif === '' && $razon !== '') {
            $razon = self::noHtml(mb_strtolower($razon, 'UTF8'));
            $sql = 'SELECT * FROM ' . $this->tableName()
                . " WHERE cifnif = '' AND lower(razonsocial) = " . $this->var2str($razon) . ';';
        } else {
            $cifnif = mb_strtolower($cifnif, 'UTF8');
            $sql = 'SELECT * FROM ' . $this->tableName() . ' WHERE lower(cifnif) = ' . $this->var2str($cifnif) . ';';
        }

        $data = $this->dataBase->select($sql);
        if (!empty($data)) {
            return new Cliente($data[0]);
        }
        return false;
    }

    /**
     * Devuelve el primer cliente que tenga $email como email.
     *
     * @param string $email
     *
     * @return bool|Cliente
     */
    public function getByEmail($email)
    {
        $email = mb_strtolower($email, 'UTF8');
        $sql = 'SELECT * FROM ' . $this->tableName() . ' WHERE lower(email) = ' . $this->var2str($email) . ';';

        $data = $this->dataBase->select($sql);
        if (!empty($data)) {
            return new Cliente($data[0]);
        }
        return false;
    }

    /**
     * Devuelve un array con las direcciones asociadas al cliente.
     * @return array
     */
    public function getDirecciones()
    {
        $dir = new DireccionCliente();
        return $dir->allFromCliente($this->codcliente);
    }

    /**
     * Devuelve un array con todas las subcuentas asociadas al cliente.
     * Una para cada ejercicio.
     * @return array
     */
    public function getSubcuentas()
    {
        $subclist = [];
        $subc = new SubcuentaCliente();
        foreach ($subc->allFromCliente($this->codcliente) as $s) {
            $s2 = $s->getSubcuenta();
            if ($s2) {
                $subclist[] = $s2;
            } else {
                $s->delete();
            }
        }

        return $subclist;
    }

    /**
     * Devuelve la subcuenta asociada al cliente para el ejercicio $eje.
     * Si no existe intenta crearla. Si falla devuelve FALSE.
     *
     * @param string $codejercicio
     *
     * @return subcuenta
     */
    public function getSubcuenta($codejercicio)
    {
        $subcuenta = false;

        foreach ($this->getSubcuentas() as $s) {
            if ($s->codejercicio === $codejercicio) {
                $subcuenta = $s;
                break;
            }
        }

        if (!$subcuenta) {
            /// intentamos crear la subcuenta y asociarla
            $continuar = true;

            $cuenta = new Cuenta();
            $ccli = $cuenta->getCuentaesp('CLIENT', $codejercicio);
            if ($ccli) {
                $continuar = false;

                $subc0 = $ccli->newSubcuenta($this->codcliente);
                if ($subc0) {
                    $subc0->descripcion = $this->razonsocial;
                    if ($subc0->save()) {
                        $continuar = true;
                    }
                }

                if ($continuar) {
                    $sccli = new SubcuentaCliente();
                    $sccli->codcliente = $this->codcliente;
                    $sccli->codejercicio = $codejercicio;
                    $sccli->codsubcuenta = $subc0->codsubcuenta;
                    $sccli->idsubcuenta = $subc0->idsubcuenta;
                    if ($sccli->save()) {
                        $subcuenta = $subc0;
                    } else {
                        $this->miniLog->alert('Imposible asociar la subcuenta para el cliente ' . $this->codcliente);
                    }
                } else {
                    $this->miniLog->alert('Imposible crear la subcuenta para el cliente ' . $this->codcliente);
                }
            } else {
                /// obtenemos una url para el mensaje, pero a prueba de errores.
                $ejeUrl = '';
                $eje0 = new Ejercicio();
                $ejercicio = $eje0->get($codejercicio);
                if ($ejercicio) {
                    $ejeUrl = $ejercicio->url();
                }

                $this->miniLog->alert('No se encuentra ninguna cuenta especial para clientes en el ejercicio '
                    . $codejercicio . ' ¿<a href="' . $ejeUrl . '">Has importado los datos del ejercicio</a>?');
            }
        }

        return $subcuenta;
    }

    /**
     * Devuelve un código que se usará como clave primaria/identificador único para este cliente.
     * @return string
     */
    public function getNewCodigo()
    {
        $sql = 'SELECT MAX(' . $this->dataBase->sql2Int('codcliente') . ') as cod FROM ' . $this->tableName() . ';';
        $cod = $this->dataBase->select($sql);
        if (!empty($cod)) {
            return sprintf('%06s', 1 + (int) $cod[0]['cod']);
        }
        return '000001';
    }

    /**
     * TODO
     * @return bool
     */
    public function test()
    {
        $status = false;

        if ($this->codcliente === null) {
            $this->codcliente = $this->getNewCodigo();
        } else {
            $this->codcliente = trim($this->codcliente);
        }

        $this->nombre = self::noHtml($this->nombre);
        $this->razonsocial = self::noHtml($this->razonsocial);
        $this->cifnif = self::noHtml($this->cifnif);
        $this->observaciones = self::noHtml($this->observaciones);

        if ($this->debaja) {
            if ($this->fechabaja === null) {
                $this->fechabaja = date('d-m-Y');
            }
        } else {
            $this->fechabaja = null;
        }

        /// validamos los dias de pago
        $arrayDias = [];
        foreach (str_getcsv($this->diaspago) as $d) {
            if ((int) $d >= 1 && (int) $d <= 31) {
                $arrayDias[] = (int) $d;
            }
        }
        $this->diaspago = null;
        if (!empty($arrayDias)) {
            $this->diaspago = implode(',', $arrayDias);
        }

        if (!preg_match('/^[A-Z0-9]{1,6}$/i', $this->codcliente)) {
            $this->miniLog->alert('Código de cliente no válido: ' . $this->codcliente);
        } elseif (empty($this->nombre) || strlen($this->nombre) > 100) {
            $this->miniLog->alert('Nombre de cliente no válido: ' . $this->nombre);
        } elseif (empty($this->razonsocial) || strlen($this->razonsocial) > 100) {
            $this->miniLog->alert('Razón social del cliente no válida: ' . $this->razonsocial);
        } else {
            $status = true;
        }

        return $status;
    }

    /**
     * TODO
     *
     * @param string $query
     * @param int $offset
     *
     * @return array
     */
    public function search($query, $offset = 0)
    {
        $clilist = [];
        $query = mb_strtolower(self::noHtml($query), 'UTF8');

        $consulta = 'SELECT * FROM ' . $this->tableName() . ' WHERE debaja = FALSE AND ';
        if (is_numeric($query)) {
            $consulta .= "(nombre LIKE '%" . $query . "%' OR razonsocial LIKE '%" . $query . "%'"
                . " OR codcliente LIKE '%" . $query . "%' OR cifnif LIKE '%" . $query . "%'"
                . " OR telefono1 LIKE '" . $query . "%' OR telefono2 LIKE '" . $query . "%'"
                . " OR observaciones LIKE '%" . $query . "%')";
        } else {
            $buscar = str_replace(' ', '%', $query);
            $consulta .= "(lower(nombre) LIKE '%" . $buscar . "%' OR lower(razonsocial) LIKE '%" . $buscar . "%'"
                . " OR lower(cifnif) LIKE '%" . $buscar . "%' OR lower(observaciones) LIKE '%" . $buscar . "%'"
                . " OR lower(email) LIKE '%" . $buscar . "%')";
        }
        $consulta .= ' ORDER BY lower(nombre) ASC';

        $data = $this->dataBase->selectLimit($consulta, FS_ITEM_LIMIT, $offset);
        if (!empty($data)) {
            foreach ($data as $d) {
                $clilist[] = new Cliente($d);
            }
        }

        return $clilist;
    }

    /**
     * Busca por cifnif.
     *
     * @param string $dni
     * @param integer $offset
     *
     * @return array
     */
    public function searchByDni($dni, $offset = 0)
    {
        $clilist = [];
        $query = mb_strtolower(self::noHtml($dni), 'UTF8');
        $consulta = 'SELECT * FROM' . $this->tableName() . ' WHERE debaja = FALSE'
            . "AND lower(cifnif) LIKE '" . $query . "%' ORDER BY lower(nombre) ASC";

        $data = $this->dataBase->selectLimit($consulta, FS_ITEM_LIMIT, $offset);
        if (!empty($data)) {
            foreach ($data as $d) {
                $clilist[] = new Cliente($d);
            }
        }

        return $clilist;
    }
}