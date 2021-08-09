<?php

namespace Tests\Invoice\Driver\Payment\AuthorizeDotNet\Helper\Name;

use Nails\Invoice\Driver\Payment\AuthorizeDotNet\Helper\Name;
use PHPUnit\Framework\TestCase;

class GetPartsTest extends TestCase
{
    public function test_method_returns_correct_value()
    {
        $aTests = [

            //  No titles
            'Rachel Green'          => [null, 'Rachel', 'Green'],

            //  Names beginning with title-like characters
            'Drake Ramoray'         => [null, 'Drake', 'Ramoray'],
            'Missy Elliot'          => [null, 'Missy', 'Elliot'],

            //  Single names
            'Gunther'               => [null, 'Gunther', null],

            //  Mr
            'Mr Chandler Bing'      => ['Mr', 'Chandler', 'Bing'],
            'Mr Bing'               => ['Mr', null, 'Bing'],
            'Mr C. Bing'            => ['Mr', 'C', 'Bing'],
            'Mr C Bing'             => ['Mr', 'C', 'Bing'],

            //  Mrs
            'Mrs Monica Bing'       => ['Mrs', 'Monica', 'Bing'],
            'Mrs Bing'              => ['Mrs', null, 'Bing'],
            'Mrs M. Bing'           => ['Mrs', 'M', 'Bing'],
            'Mrs M Bing'            => ['Mrs', 'M', 'Bing'],

            //  Ms
            'Ms Phoebe Buffay'      => ['Ms', 'Phoebe', 'Buffay'],
            'Ms Buffay'             => ['Ms', null, 'Buffay'],
            'Ms P. Buffay'          => ['Ms', 'P', 'Buffay'],
            'Ms P Buffay'           => ['Ms', 'P', 'Buffay'],

            //  Miss
            'Miss Rachel Green'     => ['Miss', 'Rachel', 'Green'],
            'Miss Green'            => ['Miss', null, 'Green'],
            'Miss R. Green'         => ['Miss', 'R', 'Green'],
            'Miss R Green'          => ['Miss', 'R', 'Green'],

            //  Mx
            'Mx Rachel Green'       => ['Mx', 'Rachel', 'Green'],
            'Mx Green'              => ['Mx', null, 'Green'],
            'Mx R. Green'           => ['Mx', 'R', 'Green'],
            'Mx R Green'            => ['Mx', 'R', 'Green'],

            //  Dr
            'Dr Ross Geller'        => ['Dr', 'Ross', 'Geller'],
            'Dr Geller'             => ['Dr', null, 'Geller'],
            'Dr R. Geller'          => ['Dr', 'R', 'Geller'],
            'Dr R Geller'           => ['Dr', 'R', 'Geller'],
            'Dr. Ross Geller'       => ['Dr', 'Ross', 'Geller'],
            'Dr. Geller'            => ['Dr', null, 'Geller'],
            'Dr. R. Geller'         => ['Dr', 'R', 'Geller'],
            'Dr. R Geller'          => ['Dr', 'R', 'Geller'],

            //  Rev
            'Rev Joey Tribbiani'    => ['Rev', 'Joey', 'Tribbiani'],
            'Rev Tribbiani'         => ['Rev', null, 'Tribbiani'],
            'Rev J. Tribbiani'      => ['Rev', 'J', 'Tribbiani'],
            'Rev J Tribbiani'       => ['Rev', 'J', 'Tribbiani'],

            //  Master
            'Master Joey Tribbiani' => ['Master', 'Joey', 'Tribbiani'],
            'Master Tribbiani'      => ['Master', null, 'Tribbiani'],
            'Master J. Tribbiani'   => ['Master', 'J', 'Tribbiani'],
            'Master J Tribbiani'    => ['Master', 'J', 'Tribbiani'],

            //  Sir
            'Sir Joey Tribbiani'    => ['Sir', 'Joey', 'Tribbiani'],
            'Sir Tribbiani'         => ['Sir', null, 'Tribbiani'],
            'Sir J. Tribbiani'      => ['Sir', 'J', 'Tribbiani'],
            'Sir J Tribbiani'       => ['Sir', 'J', 'Tribbiani'],

            //  Lady
            'Lady Rachel Green'     => ['Lady', 'Rachel', 'Green'],
            'Lady Green'            => ['Lady', null, 'Green'],
            'Lady R. Green'         => ['Lady', 'R', 'Green'],
            'Lady R Green'          => ['Lady', 'R', 'Green'],

            //  Madam
            'Madam Rachel Green'    => ['Madam', 'Rachel', 'Green'],
            'Madam Green'           => ['Madam', null, 'Green'],
            'Madam R. Green'        => ['Madam', 'R', 'Green'],
            'Madam R Green'         => ['Madam', 'R', 'Green'],

            //  Dame
            'Dame Rachel Green'     => ['Dame', 'Rachel', 'Green'],
            'Dame Green'            => ['Dame', null, 'Green'],
            'Dame R. Green'         => ['Dame', 'R', 'Green'],
            'Dame R Green'          => ['Dame', 'R', 'Green'],

            //  Lord
            'Lord Joey Tribbiani'   => ['Lord', 'Joey', 'Tribbiani'],
            'Lord Tribbiani'        => ['Lord', null, 'Tribbiani'],
            'Lord J. Tribbiani'     => ['Lord', 'J', 'Tribbiani'],
            'Lord J Tribbiani'      => ['Lord', 'J', 'Tribbiani'],

            //  Esq
            'Esq Joey Tribbiani'    => ['Esq', 'Joey', 'Tribbiani'],
            'Esq Tribbiani'         => ['Esq', null, 'Tribbiani'],
            'Esq J. Tribbiani'      => ['Esq', 'J', 'Tribbiani'],
            'Esq J Tribbiani'       => ['Esq', 'J', 'Tribbiani'],
        ];

        foreach ($aTests as $sName => $aExpectedParts) {

            $aResult = Name::getParts($sName);

            $this->assertIsArray($aResult);
            $this->assertCount(3, $aResult);
            $this->assertSame($aExpectedParts[0], $aResult[0]);
            $this->assertSame($aExpectedParts[1], $aResult[1]);
            $this->assertSame($aExpectedParts[2], $aResult[2]);
        }
    }
}
